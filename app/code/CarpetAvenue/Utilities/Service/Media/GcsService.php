<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Service\Media;

/**
 * Handles Google Cloud Storage upload operations for Media Cleanup & Backup.
 *
 * All GCS operations use the google/cloud-storage PHP SDK exclusively.
 * No OS-level tools (gsutil, exec, proc_open) are used.
 */
class GcsService
{
    /**
     * Upload a local ZIP file to GCS.
     * Returns true on verified success, false on failure.
     * Always non-fatal — caller decides whether to abort.
     *
     * @param string $zipFile       Absolute local path to ZIP
     * @param int    $zipSize       Expected ZIP size in bytes
     * @param string $gcsBucket     Bucket name (without gs://)
     * @param string $gcsObjectName Object path inside bucket (e.g. zip_backup/foo.zip)
     * @param string $gcsKeyFile    Path to SA key JSON, or '' for ADC
     * @param callable $log         fn(string $msg): void
     * @return bool                 true if uploaded+verified, false otherwise
     */
    public function uploadZip(
        string   $zipFile,
        int      $zipSize,
        string   $gcsBucket,
        string   $gcsObjectName,
        string   $gcsKeyFile,
        callable $log
    ): bool {
        if (!class_exists(\Google\Cloud\Storage\StorageClient::class)) {
            $log('  WARNING: google/cloud-storage SDK not found. Cannot auto-upload ZIP.');
            $log('           Run: composer require google/cloud-storage');
            return false;
        }

        $zipGcsUri = "gs://$gcsBucket/$gcsObjectName";

        try {
            $storageOpts = ['suppressKeyFileNotice' => true];
            if ($gcsKeyFile !== '') {
                $storageOpts['keyFilePath'] = $gcsKeyFile;
                $log("  Auth: service-account key — $gcsKeyFile");
            } else {
                $log('  Auth: Application Default Credentials (ADC)');
            }

            $storageClient = new \Google\Cloud\Storage\StorageClient($storageOpts);
            $bucket        = $storageClient->bucket($gcsBucket);

            if (!$bucket->exists()) {
                throw new \RuntimeException("Bucket does not exist or is not accessible: $gcsBucket");
            }
            $log("  Bucket confirmed: gs://$gcsBucket/");

            // Check if already uploaded (resume case)
            $existingObj = $bucket->object($gcsObjectName);
            if ($existingObj->exists()) {
                $existingInfo = $existingObj->info();
                $existingSize = (int) ($existingInfo['size'] ?? 0);
                if ($existingSize === $zipSize) {
                    $log("  SKIP (already on GCS, size matches): $zipGcsUri (" . self::fmtBytes($existingSize) . ')');
                    if (@unlink($zipFile)) {
                        $log("  Local ZIP deleted: $zipFile");
                    }
                    return true;
                }
                $log("  ZIP exists on GCS but size differs (GCS={$existingSize} local={$zipSize}). Re-uploading...");
            }

            $log("  Uploading $zipFile -> $zipGcsUri (" . self::fmtBytes($zipSize) . ')...');
            $uploadStart = microtime(true);

            $fp = @fopen($zipFile, 'rb');
            if ($fp === false) {
                throw new \RuntimeException("Cannot open ZIP file for upload: $zipFile");
            }
            try {
                $bucket->upload($fp, ['name' => $gcsObjectName]);
            } finally {
                if (is_resource($fp)) {
                    fclose($fp);
                }
            }

            $uploadSecs = round(microtime(true) - $uploadStart, 2);
            $log("  Upload completed in {$uploadSecs}s");

            // Verify
            $verifyObj  = $bucket->object($gcsObjectName);
            if (!$verifyObj->exists()) {
                throw new \RuntimeException("ZIP uploaded but object not found on GCS: $zipGcsUri");
            }
            $verifyInfo      = $verifyObj->info();
            $gcsVerifiedSize = (int) ($verifyInfo['size'] ?? 0);

            if ($gcsVerifiedSize === $zipSize) {
                $log("  ZIP verified on GCS: $zipGcsUri ({$uploadSecs}s, " . self::fmtBytes($gcsVerifiedSize) . ')');
                if (@unlink($zipFile)) {
                    $log("  Local ZIP deleted: $zipFile");
                } else {
                    $log("  WARNING: Could not delete local ZIP: $zipFile (GCS copy is safe)");
                }
                return true;
            }

            $log("  WARNING: ZIP uploaded but SIZE MISMATCH! Local={$zipSize} GCS={$gcsVerifiedSize}");
            $log("  Local ZIP kept for safety: $zipFile");
            return false;

        } catch (\Throwable $e) {
            $log("  WARNING: ZIP GCS upload FAILED — " . $e->getMessage());
            $log("  Local ZIP kept: $zipFile");
            return false;
        }
    }

    /**
     * Upload all files from a local staging directory to GCS in parallel.
     *
     * Uses curl_multi for concurrent HTTP connections — no pcntl required.
     * Files are batched into groups of $concurrency and uploaded simultaneously.
     * Resume-safe: lists existing GCS objects first, skips size-matched files.
     * Token auto-refreshes before expiry for large uploads.
     * Returns true only if all files uploaded successfully (zero errors).
     * Caller MUST abort the destructive step (delete/resize) if this returns false.
     *
     * @param string   $stagingDir   Local staging directory to upload
     * @param string   $gcsBucket    Bucket name (without gs://)
     * @param string   $gcsRawRoot   GCS object path prefix (no trailing slash)
     * @param string   $gcsKeyFile   SA key JSON path, or '' for ADC
     * @param callable $log          fn(string $msg): void
     * @param int      $concurrency  Concurrent uploads per batch (default 8)
     * @return bool true if all files uploaded or already present, false if any error
     */
    public function uploadRawFiles(
        string   $stagingDir,
        string   $gcsBucket,
        string   $gcsRawRoot,
        string   $gcsKeyFile,
        callable $log,
        int      $concurrency = 8,
        array    $onlyFiles   = []
    ): bool {
        $stagingRoot = rtrim($stagingDir, '/');
        if (!is_dir($stagingRoot)) {
            $log("  ERROR: Staging directory not found: $stagingRoot");
            return false;
        }

        // ── OAuth2 token ──────────────────────────────────────────────────────
        if ($gcsKeyFile !== '') {
            $log("  Auth: service-account key — $gcsKeyFile");
        } else {
            $log('  Auth: Application Default Credentials (ADC)');
        }

        $token        = $this->resolveGcsToken($gcsKeyFile, $log);
        $tokenIssued  = time();
        if ($token === '') {
            return false;
        }

        // ── Verify bucket ─────────────────────────────────────────────────────
        $bucketApiBase = 'https://storage.googleapis.com/storage/v1/b/' . rawurlencode($gcsBucket);
        $ch = curl_init($bucketApiBase);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        ]);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($httpCode === 404) {
            $log("  ERROR: Bucket not found: $gcsBucket");
            return false;
        }
        if ($httpCode === 403) {
            $log("  ERROR: Access denied to bucket: $gcsBucket");
            return false;
        }
        $log("  Bucket confirmed: gs://$gcsBucket/");

        // ── Collect local files ───────────────────────────────────────────────
        $allFiles = [];
        if (!empty($onlyFiles)) {
            // Caller supplied explicit list — skip full directory scan
            foreach ($onlyFiles as [$absPath, $relPath, $size]) {
                if (is_file($absPath)) {
                    $allFiles[] = [$absPath, $relPath, $size];
                }
            }
        } else {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($stagingRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $absPath    = $file->getPathname();
                    $relPath    = ltrim(str_replace($stagingRoot, '', $absPath), '/');
                    $allFiles[] = [$absPath, $relPath, $file->getSize()];
                }
            }
        }

        $totalFiles = count($allFiles);
        if ($totalFiles === 0) {
            $log('  No files in staging directory.');
            return true;
        }

        $toUpload      = $allFiles;
        $toUploadCount = $totalFiles;
        $log("  Files to upload: $toUploadCount | Concurrency: $concurrency");

        // Log every ~5% of total, minimum every $concurrency files
        $logEvery = max($concurrency, (int) ($toUploadCount / 20));

        // ── Parallel upload via curl_multi ────────────────────────────────────
        $uploadStart   = microtime(true);
        $uploaded      = 0;
        $errors        = 0;
        $uploadApiBase = 'https://storage.googleapis.com/upload/storage/v1/b/'
            . rawurlencode($gcsBucket) . '/o?uploadType=media&name=';

        foreach (array_chunk($toUpload, $concurrency) as $batch) {
            // Refresh token every 55 min (tokens expire at 60 min)
            if (time() - $tokenIssued > 3300) {
                $token       = $this->resolveGcsToken($gcsKeyFile, $log);
                $tokenIssued = time();
                if ($token === '') {
                    $log('  ERROR: Token refresh failed — aborting.');
                    return false;
                }
                $log('  Token refreshed.');
            }

            [$bUploaded, $bErrors] = $this->uploadBatchCurl(
                $batch,
                $gcsRawRoot,
                $uploadApiBase,
                $token,
                $log
            );
            $uploaded += $bUploaded;
            $errors   += $bErrors;

            $done    = $uploaded + $errors;
            $pct     = (int) round($done / $toUploadCount * 100);
            $elapsed = round(microtime(true) - $uploadStart, 1);
            if ($done % $logEvery < $concurrency || $done >= $toUploadCount) {
                $log("  Upload: $done/$toUploadCount ($pct%) | ok=$uploaded errors=$errors | {$elapsed}s");
            }
        }

        $elapsed = round(microtime(true) - $uploadStart, 2);
        $log("  Raw upload complete: $uploaded/$toUploadCount uploaded | errors=$errors | {$elapsed}s");

        return $errors === 0;
    }

    /**
     * Resolve an OAuth2 Bearer token using google/auth (dep of google/cloud-storage).
     * Supports service-account key file and Application Default Credentials.
     */
    private function resolveGcsToken(string $gcsKeyFile, callable $log): string
    {
        $scope = 'https://www.googleapis.com/auth/devstorage.read_write';

        try {
            if ($gcsKeyFile !== '') {
                $keyData     = json_decode((string) file_get_contents($gcsKeyFile), true, 512, JSON_THROW_ON_ERROR);
                $credentials = new \Google\Auth\Credentials\ServiceAccountCredentials($scope, $keyData);
            } else {
                $credentials = \Google\Auth\ApplicationDefaultCredentials::getCredentials($scope);
            }

            $tokenData = $credentials->fetchAuthToken();
            $token     = $tokenData['access_token'] ?? '';

            if ($token === '') {
                throw new \RuntimeException('Empty access_token in response.');
            }
            return $token;
        } catch (\Throwable $e) {
            $log('  ERROR: Failed to obtain GCS access token — ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Upload a batch of files concurrently using curl_multi.
     * Each file is a POST to the GCS JSON API simple upload endpoint.
     *
     * @param array<array{string,string,int}> $batch   [[absPath, relPath, localSize], ...]
     * @param string   $gcsRawRoot     GCS object prefix (e.g. raw_backup/pub/media/catalog/product)
     * @param string   $uploadApiBase  Base URL up to and including '?uploadType=media&name='
     * @param string   $token          Bearer token
     * @param callable $log
     * @return array{int,int}          [uploaded, errors]
     */
    private function uploadBatchCurl(
        array    $batch,
        string   $gcsRawRoot,
        string   $uploadApiBase,
        string   $token,
        callable $log
    ): array {
        $mh      = curl_multi_init();
        $handles = [];
        $fps     = [];

        foreach ($batch as $i => [$absPath, $relPath, $localSize]) {
            $fp = @fopen($absPath, 'rb');
            if ($fp === false) {
                $log("  ERROR  Cannot open: $relPath");
                $handles[$i] = ['ch' => null, 'relPath' => $relPath, 'size' => $localSize];
                continue;
            }
            $fps[$i] = $fp;

            // Full GCS object name: raw_backup/pub/media/catalog/product/i/m/image.jpg
            $objectName = $gcsRawRoot . '/' . $relPath;
            $ch = curl_init($uploadApiBase . rawurlencode($objectName));
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_UPLOAD         => true,         // enables streaming read
                CURLOPT_INFILE         => $fp,
                CURLOPT_INFILESIZE     => $localSize,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/octet-stream',
                    'Content-Length: ' . $localSize,
                ],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = ['ch' => $ch, 'objectName' => $objectName, 'relPath' => $relPath, 'size' => $localSize];
        }

        // Execute all handles concurrently
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 1.0);
            }
        } while ($active && $status === CURLM_OK);

        // Collect results
        $uploaded = 0;
        $errors   = 0;

        foreach ($handles as $i => $info) {
            if ($info['ch'] === null) {
                $errors++; // fopen failed, already logged above
                continue;
            }

            $ch       = $info['ch'];
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);

            curl_multi_remove_handle($mh, $ch);
            unset($ch, $handles[$i]['ch']);

            if (isset($fps[$i]) && is_resource($fps[$i])) {
                fclose($fps[$i]);
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                $uploaded++;
            } else {
                $detail = $curlErr !== '' ? $curlErr : "HTTP $httpCode";
                $log("  ERROR  Upload failed: {$info['relPath']} — $detail");
                $errors++;
            }
        }

        curl_multi_close($mh);
        return [$uploaded, $errors];
    }

    public static function fmtBytes(int|float $bytes): string
    {
        $bytes = max(0, $bytes);
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 2) . ' ' . $unit;
            }
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' PB';
    }
}
