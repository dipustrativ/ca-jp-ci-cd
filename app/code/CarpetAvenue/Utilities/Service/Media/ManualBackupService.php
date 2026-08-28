<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Service\Media;

use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * Manual Backup Service.
 *
 * Accepts a parsed list of relative image paths, stages each found file,
 * uploads the staging directory to GCS, and writes a result CSV.
 *
 * Paths in the input are relative to pub/media/catalog/product/
 * (e.g. "a/i/ai.jpg" or "cache/hash/x/y/img.jpg").
 *
 * Result CSV columns: image_path, backup, bucket_path, note
 */
class ManualBackupService
{
    public const LOG_DIR = 'manual_backup_delete';

    // Allowed characters in a relative image path segment
    private const PATH_PATTERN = '#^[a-zA-Z0-9_\-./]+$#';

    // Valid image/cache extensions
    private const ALLOWED_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tiff', 'tif'];

    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly GcsService    $gcsService,
    ) {
    }

    /**
     * Parse and validate a raw CSV file.
     * Returns ['rows' => [...], 'error' => string|null].
     * Each valid row: ['image_path' => string].
     * Invalid rows are returned with an 'error' key instead.
     */
    public function parseAndValidateCsv(string $csvFilePath): array
    {
        if (!is_readable($csvFilePath)) {
            return ['rows' => [], 'error' => "CSV file not readable: $csvFilePath"];
        }

        $handle = fopen($csvFilePath, 'r');
        if ($handle === false) {
            return ['rows' => [], 'error' => 'Cannot open CSV file.'];
        }

        // Read header
        $rawHeader = fgetcsv($handle, 0, ',', '"', '\\');
        if ($rawHeader === false) {
            fclose($handle);
            return ['rows' => [], 'error' => 'CSV is empty or has no header row.'];
        }

        $header  = array_map(static fn($h) => strtolower(trim((string) $h)), $rawHeader);
        $pathCol = array_search('image_path', $header, true);

        if ($pathCol === false) {
            fclose($handle);
            return ['rows' => [], 'error' => "CSV must have an 'image_path' column header. Found: " . implode(', ', $rawHeader)];
        }

        $rows    = [];
        $lineNum = 1;

        while (($raw = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $lineNum++;
            $relPath = isset($raw[$pathCol]) ? trim((string) $raw[$pathCol]) : '';

            if ($relPath === '') {
                continue; // skip blank rows
            }

            // Security: reject path traversal, absolute paths, null bytes
            if (
                str_contains($relPath, '..') ||
                str_contains($relPath, "\0") ||
                str_starts_with($relPath, '/') ||
                str_starts_with($relPath, '\\')
            ) {
                $rows[] = ['image_path' => $relPath, '_error' => "Line $lineNum: path traversal or absolute path rejected"];
                continue;
            }

            // Allow only safe characters
            if (!preg_match(self::PATH_PATTERN, $relPath)) {
                $rows[] = ['image_path' => $relPath, '_error' => "Line $lineNum: path contains invalid characters"];
                continue;
            }

            // Check extension
            $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_EXTS, true)) {
                $rows[] = ['image_path' => $relPath, '_error' => "Line $lineNum: unsupported extension .$ext"];
                continue;
            }

            $rows[] = ['image_path' => $relPath];
        }

        fclose($handle);

        if (empty($rows)) {
            return ['rows' => [], 'error' => 'No valid image paths found in CSV.'];
        }

        return ['rows' => $rows, 'error' => null];
    }

    /**
     * Process validated rows: find → stage → upload → write result CSV.
     *
     * Options:
     *   bucket       string  GCS bucket name
     *   credentials  string  Path to SA key JSON, or '' for ADC
     *   gcs_prefix   string  GCS object path prefix (default: manual_backup)
     *
     * Returns absolute path of result CSV on success, null on fatal error.
     */
    public function execute(array $rows, array $options, callable $log): ?string
    {
        $gcsBucket  = trim((string) ($options['bucket']      ?? ''));
        $gcsKeyFile = trim((string) ($options['credentials'] ?? ''));
        $gcsPrefix  = trim(trim((string) ($options['gcs_prefix'] ?? 'manual_backup')), '/');

        if ($gcsPrefix === '') {
            $gcsPrefix = 'manual_backup';
        }

        $bp         = $this->directoryList->getRoot();
        $mediaBase  = $bp . '/pub/media/catalog/product';
        $logDir     = $bp . '/var/log/carpetavenue/' . self::LOG_DIR;
        $timestamp  = date('Ymd_His');
        $stagingDir = $logDir . '/staging_' . $timestamp;

        if (!is_dir($logDir) && !mkdir($logDir, 0750, true)) {
            $log("ERROR: Cannot create log dir: $logDir");
            return null;
        }
        if (!mkdir($stagingDir, 0750, true)) {
            $log("ERROR: Cannot create staging dir: $stagingDir");
            return null;
        }

        $total    = count($rows);
        $results  = [];
        $stagedMap = []; // relPath => stagingAbsPath

        $log("Processing $total image path(s)...");

        // ── Stage files ───────────────────────────────────────────────────────
        foreach ($rows as $i => $row) {
            $relPath = $row['image_path'];
            $n       = $i + 1;
            $pct     = (int) round($n / $total * 100);
            $pfx     = "$n/$total ($pct%)";

            // Already flagged as invalid during validation
            if (isset($row['_error'])) {
                $log("  SKIP  [$pfx] $relPath — {$row['_error']}");
                $results[$relPath] = ['image_path' => $relPath, 'backup' => 'no', 'bucket_path' => '', 'note' => $row['_error']];
                continue;
            }

            $absPath = $mediaBase . '/' . ltrim($relPath, '/');

            if (!file_exists($absPath) || !is_file($absPath)) {
                $log("  NOT FOUND  [$pfx] $relPath");
                $results[$relPath] = ['image_path' => $relPath, 'backup' => 'no', 'bucket_path' => '', 'note' => 'file not found'];
                continue;
            }

            // Stage
            $stagingAbsPath = $stagingDir . '/' . ltrim($relPath, '/');
            $stagingFileDir = dirname($stagingAbsPath);

            if (!is_dir($stagingFileDir) && !mkdir($stagingFileDir, 0750, true)) {
                $log("  ERROR  [$pfx] Cannot create staging subdir for: $relPath");
                $results[$relPath] = ['image_path' => $relPath, 'backup' => 'no', 'bucket_path' => '', 'note' => 'staging mkdir failed'];
                continue;
            }

            if (@copy($absPath, $stagingAbsPath)) {
                $stagedMap[$relPath] = $stagingAbsPath;
                $log("  STAGED  [$pfx] $relPath");
            } else {
                $err = error_get_last()['message'] ?? 'copy() failed';
                $log("  ERROR  [$pfx] Cannot stage: $relPath — $err");
                $results[$relPath] = ['image_path' => $relPath, 'backup' => 'no', 'bucket_path' => '', 'note' => "staging failed: $err"];
            }
        }

        $stagedCount = count($stagedMap);
        $log("Staged: $stagedCount | Errors/Not found: " . ($total - $stagedCount - count($results) + count(array_filter($results, fn($r) => isset($r['note']) && str_contains($r['note'], 'found')))));

        // ── GCS upload ────────────────────────────────────────────────────────
        $uploadOk = false;

        if ($stagedCount > 0) {
            if ($gcsBucket !== '') {
                $log("Uploading $stagedCount staged file(s) to gs://$gcsBucket/$gcsPrefix/ ...");
                $uploadOk = $this->gcsService->uploadRawFiles(
                    $stagingDir,
                    $gcsBucket,
                    $gcsPrefix,
                    $gcsKeyFile,
                    $log
                );
                if (!$uploadOk) {
                    $log("WARNING: GCS upload failed or had errors — staged files kept locally: $stagingDir");
                } else {
                    $this->removeDirectory($stagingDir);
                    $log("Staging directory removed (GCS upload confirmed).");
                }
            } else {
                $log("No GCS bucket configured — files staged locally only: $stagingDir");
            }
        }

        // ── Mark results for staged files and remove originals if backed up ──
        foreach ($stagedMap as $relPath => $stagingAbsPath) {
            $absPath = $mediaBase . '/' . ltrim($relPath, '/');
            if ($gcsBucket === '') {
                $results[$relPath] = [
                    'image_path'  => $relPath,
                    'backup'      => 'staged_only',
                    'bucket_path' => '',
                    'note'        => 'staged locally — no GCS bucket configured',
                ];
            } elseif ($uploadOk) {
                $results[$relPath] = [
                    'image_path'  => $relPath,
                    'backup'      => 'yes',
                    'bucket_path' => "gs://$gcsBucket/$gcsPrefix/" . ltrim($relPath, '/'),
                    'note'        => '',
                ];
                // Remove the original file after successful backup
                if (file_exists($absPath) && is_file($absPath)) {
                    if (@unlink($absPath)) {
                        $log("  REMOVED ORIGINAL  $relPath");
                    } else {
                        $log("  ERROR  Could not remove original: $relPath");
                    }
                } else {
                    $log("  SKIP REMOVE  Original not found: $relPath");
                }
            } else {
                $results[$relPath] = [
                    'image_path'  => $relPath,
                    'backup'      => 'no',
                    'bucket_path' => '',
                    'note'        => 'GCS upload failed',
                ];
            }
        }

        // ── Write result CSV ──────────────────────────────────────────────────
        $resultCsvPath = $logDir . '/result_' . $timestamp . '.csv';
        $rfh           = fopen($resultCsvPath, 'w');
        if ($rfh === false) {
            $log("ERROR: Cannot write result CSV: $resultCsvPath");
            return null;
        }

        fputcsv($rfh, ['image_path', 'bucket_full_path'], ',', '"', '\\');
        // Preserve original row order
        foreach ($rows as $row) {
            $relPath = $row['image_path'];
            $r       = $results[$relPath] ?? ['image_path' => $relPath, 'bucket_path' => ''];
            fputcsv($rfh, [$r['image_path'], $r['bucket_path']], ',', '"', '\\');
        }
        fclose($rfh);

        // Save pointer to latest result
        file_put_contents($logDir . '/latest_result.txt', $resultCsvPath);

        $backed = count(array_filter($results, fn($r) => $r['backup'] === 'yes'));
        $log("Done. Backed up: $backed/$total | Result CSV: $resultCsvPath");

        return $resultCsvPath;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }
}
