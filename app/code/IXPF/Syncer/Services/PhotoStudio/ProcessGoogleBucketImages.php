<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\PhotoStudio;

use DateTime;
use Exception;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Helpers as ProductHelper;
use IXPF\Syncer\Helper\Logger\PhotoStudioLogger;
use Magento\Framework\App\ResourceConnection;
use RuntimeException;

class ProcessGoogleBucketImages
{
    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------
    const PHOTO_STUDIO_BUCKET_IMAGES_TABLE = 'ixpf_photo_studio_bucket_images';
    const STATUS_DOWNLOADED  = 'downloaded';
    const STATUS_FAILED      = 'failed';
    const STATUS_PENDING     = 'pending';
    const STATUS_PROCESSING  = 'processing';

    // -------------------------------------------------------------------------
    // Config
    // -------------------------------------------------------------------------
    private int   $concurrency    = 20;
    private int   $maxRetries     = 3;
    private mixed $commandLineLog = null;

    /** Access token reused across downloadObject() calls in one process. Tokens live 3600s. */
    private ?string $cachedAccessToken = null;
    private int     $cachedTokenExpiry = 0;

    public function __construct(
        protected readonly Config            $configHelper,
        protected ProductHelper              $productHelper,
        protected readonly PhotoStudioLogger $logger,
        protected ResourceConnection         $resourceConnection
    ) {}

    /**
     * Download one bucket object to an absolute local path.
     *
     * The batch process() path above is queue-table driven and cannot fetch an arbitrary
     * object, so this is the entry point for callers that already know the path they want
     * (review photos, bought-item thumbnails). It reuses the same service-account auth
     * rather than standing up a second GCS client.
     *
     * @throws RuntimeException on auth failure, a non-200 response or an unwritable target
     */
    public function downloadObject(string $objectPath, string $savePath): void
    {
        // Both guards are permanent failures, so they carry a 4xx code for the caller's retry
        // loop - no amount of retrying conjures a credentials file. The credentials check also
        // has to come first: getGoogleBucketConfigJsonFile() returns "" when the file is absent,
        // and the resulting file_get_contents("") surfaces as "Path cannot be empty", which
        // points nowhere near the real cause.
        if ($this->productHelper->getGoogleBucketConfigJsonFile() === '') {
            throw new RuntimeException('Google bucket credentials file is missing or not configured', 400);
        }

        $bucketName = $this->productHelper->getGoogleBucketName();
        if ($bucketName === '') {
            throw new RuntimeException('Google bucket name is not configured', 400);
        }

        $this->ensureDirectoryExists(dirname($savePath));

        $handle = fopen($savePath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open for writing: ' . $savePath);
        }

        $ch = curl_init(sprintf(
            'https://storage.googleapis.com/storage/v1/b/%s/o/%s?alt=media',
            urlencode($bucketName),
            rawurlencode($objectPath)
        ));
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->getCachedAccessToken()],
            CURLOPT_FILE           => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FAILONERROR    => false,
        ]);

        $ok    = curl_exec($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        // No curl_close(): deprecated in PHP 8.5 and a no-op since 8.0 — dropping the
        // last reference is what frees the handle (and its ref to $handle's stream).
        unset($ch);
        fclose($handle);

        if ($ok === false || $code !== 200) {
            @unlink($savePath);
            // Exception code carries the HTTP status so callers can tell a permanent 4xx (bad
            // path, no access - retrying will never help) from a transient failure. Curl-level
            // failures leave it 0, which reads as transient.
            throw new RuntimeException(
                sprintf(
                    'GCS download failed for "%s": HTTP %d%s',
                    $objectPath,
                    $code,
                    $error !== '' ? ' ' . $error : ''
                ),
                $code
            );
        }
    }

    /**
     * Token valid for 3600s upstream; refreshed a minute early to avoid an edge expiry.
     */
    private function getCachedAccessToken(): string
    {
        if ($this->cachedAccessToken === null || time() >= $this->cachedTokenExpiry) {
            $this->cachedAccessToken = $this->getAccessToken();
            $this->cachedTokenExpiry = time() + 3540;
        }

        return $this->cachedAccessToken;
    }


    /**
     * Download pending bucket images.
     *
     * @return bool False when any download failed or the run aborted with an error.
     */
    public function process(int $concurrency = 20, $commandLineOutput = null, $downloadLimit = 250): bool
    {
        try {
            $this->concurrency    = $concurrency;
            $this->commandLineLog = $commandLineOutput;

            $this->validateConfiguration();

            $bucketName      = $this->productHelper->getGoogleBucketName();
            $downloadBaseDir = $this->productHelper->getGoogleBucketImageInitDownloadPath();

            $this->ensureDirectoryExists(dirname($downloadBaseDir));

            // Reset any records stuck in 'processing' from a previous crashed run
            $this->resetStuckProcessingRecords();

            $pendingImages = $this->getPendingImages($downloadLimit);

            if (empty($pendingImages)) {
                $this->log('No pending images found.', 'info', 'comment');
                return true;
            }

            $this->markAsProcessing(array_column($pendingImages, 'entity_id'));

            [$files, $skipped] = $this->buildDownloadQueue($pendingImages, $bucketName, $downloadBaseDir);

            if ($skipped > 0) {
                $this->log("Skipped $skipped files (already on disk with content or invalid path).", 'warning', 'comment');
            }

            if (empty($files)) {
                $this->log('No files to download — all pending images are already on disk.', 'info', 'comment');
                return true;
            }

            $this->log('Starting download of ' . count($files) . ' files from Google Bucket.', 'info', 'info');

            $accessToken = $this->authenticate();

            [$success, $failed] = $this->downloadFiles($files, $accessToken);

            $this->persistDownloadResults($success, $failed);

            if (!empty($failed)) {
                $this->log(count($failed) . ' file(s) failed to download.', 'error', 'error', true);
                return false;
            }

            return true;

        } catch (Exception $e) {
            $this->log('Error processing Google Bucket images: ' . $e->getMessage(), 'error', 'error', true);
            return false;
        }
    }

    /**
     * Validate required Google Bucket configuration before processing.
     */
    private function validateConfiguration(): void
    {
        if (!$this->productHelper->getGoogleBucketConfigJsonFile()) {
            throw new Exception('Google Bucket JSON file configuration is missing.');
        }

        if (!$this->productHelper->getGoogleBucketName()) {
            throw new Exception('Google Bucket name configuration is missing.');
        }
    }

    /**
     * Create directory if it does not already exist.
     */
    private function ensureDirectoryExists(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new Exception('Failed to create directory: ' . $dir);
        }
    }

    /**
     * Fetch pending images from the queue table, oldest first.
     */
    public function getPendingImages(int $limit = 250): array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName  = $this->resourceConnection->getTableName(self::PHOTO_STUDIO_BUCKET_IMAGES_TABLE);

        $select = $connection->select()
            ->from($tableName)
            ->where('status = ?', self::STATUS_PENDING)
            ->order('created_at ASC')
            ->limit($limit);

        return $connection->fetchAll($select);
    }

    /**
     * Convert raw DB rows into a structured download queue.
     *
     * Fixes applied:
     *  - basename() + sanitize so only the filename (not full path) is used.
     *  - filesize() > 0 check so empty leftover files are re-downloaded.
     *  - empty(trim()) check for invalid paths instead of null-only.
     *
     * Returns [ $files[], $skippedCount ]
     */
    private function buildDownloadQueue(array $pendingImages, string $bucketName, string $downloadBaseDir): array
    {
        $files         = [];
        $skipped       = 0;
        $alreadyExists = [];

        foreach ($pendingImages as $image) {
            // FIX 3: skip null, empty string, or whitespace-only paths
            if ($this->shouldSkipImage($image, $skipped)) {
                continue;
            }

            // FIX 1: extract just the filename from the full bucket path,
            // then sanitize only that filename — not the whole path.
            // e.g. "caProdMagento/products/abk543490/images/standard/abk543490_160x230_cm_barcelona.jpg"
            //   -> basename -> "abk543490_160x230_cm_barcelona.jpg"
            //   -> sanitize -> "abk543490_160x230_cm_barcelona.jpg"  (unchanged, already clean)
            $filename = $this->sanitizeFilename(basename($image['bucket_image_path']));
            $savePath = $downloadBaseDir . $filename;

            if (file_exists($savePath)) {
                // FIX 2: only treat the file as valid if it actually has content.
                // An empty file means a previous run created it but the download
                // failed/was interrupted — delete it and re-download.
                if (filesize($savePath) > 0) {
                    $alreadyExists[$image['entity_id']] = $image['bucket_image_path'];
                    $skipped++;
                    continue;
                }

                // Empty file — clean it up and fall through to re-queue
                @unlink($savePath);
                $this->log(
                    'Found empty file for entity_id ' . $image['entity_id'] . ' (' . $filename . '), re-queuing for download.',
                    'warning', 'comment', true
                );
            }

            $files[] = $this->buildFileEntry($image, $bucketName, $savePath, $filename);
        }

        // Persist 'downloaded' status for files that were already on disk with content
        if (!empty($alreadyExists)) {
            $this->markAsDownloaded($alreadyExists);
            $this->log('Marked ' . count($alreadyExists) . ' already-existing files as downloaded.', 'info', 'comment');
        }

        return [$files, $skipped];
    }

    /**
     * Check if the image row should be skipped (missing / blank / null path).
     *
     * FIX 3: use empty(trim()) instead of === null so empty strings and
     * whitespace-only values are also caught and never queued.
     */
    private function shouldSkipImage(array $image, int &$skipped): bool
    {
        if (empty(trim((string) ($image['bucket_image_path'] ?? '')))) {
            $skipped++;
            $this->log(
                'Skipping image with entity_id ' . $image['entity_id'] . ' due to missing/invalid bucket_image_path',
                'warning',
                'alert',
                true
            );
            return true;
        }

        return false;
    }

    /**
     * Replace unsafe characters in a filename.
     *
     * NOTE: call basename() on the full bucket path BEFORE passing here,
     * so the slashes are already stripped and only the leaf filename is sanitized.
     */
    private function sanitizeFilename(string $filename): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    }

    /**
     * Build a single file download entry array.
     *
     * $filename is the already-sanitized leaf name (no directory separators).
     */
    private function buildFileEntry(array $image, string $bucketName, string $savePath, string $filename): array
    {
        $objectPath = $image['bucket_image_path']; // raw full object path — used for GCS URL

        return [
            'entity_id' => $image['entity_id'],
            'object'    => $objectPath,
            'filename'  => $filename,
            'api_url'   => sprintf(
                'https://storage.googleapis.com/storage/v1/b/%s/o/%s?alt=media',
                urlencode($bucketName),
                rawurlencode($objectPath)  // full object path required by GCS API
            ),
            'save_path' => $savePath,
        ];
    }

    /**
     * Obtain a GCS OAuth2 access token via a signed JWT.
     */
    private function authenticate(): string
    {
        $this->log('Authenticating with GCS...', 'info', 'comment');
        $accessToken = $this->getAccessToken();
        $this->log('Authentication successful. Access token obtained.', 'info', 'comment');

        return $accessToken;
    }

    /**
     * Build and exchange a JWT for a GCS Bearer token.
     */
    private function getAccessToken(): string
    {
        $key   = json_decode(file_get_contents($this->productHelper->getGoogleBucketConfigJsonFile()), true);
        $jwt   = $this->buildJwt($key);
        $token = $this->exchangeJwtForToken($jwt);

        if (empty($token['access_token'])) {
            throw new RuntimeException('GCS auth failed: ' . json_encode($token));
        }

        return $token['access_token'];
    }

    /**
     * Build a signed RS256 JWT for the GCS service account.
     */
    private function buildJwt(array $key): string
    {
        $b64 = fn($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');

        $now    = time();
        $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $b64(json_encode([
            'iss'   => $key['client_email'],
            'scope' => 'https://www.googleapis.com/auth/devstorage.read_only',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        openssl_sign("$header.$claims", $signature, $key['private_key'], 'SHA256');

        return "$header.$claims." . $b64($signature);
    }

    /**
     * POST the JWT to the Google token endpoint and return the decoded response.
     */
    private function exchangeJwtForToken(string $jwt): array
    {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = json_decode(curl_exec($ch), true);
        unset($ch);

        return $response ?? [];
    }

    /**
     * Download all files in concurrent batches with automatic retry.
     * Returns [ $success[], $failed[] ]
     */
    private function downloadFiles(array $files, string $accessToken): array
    {
        $success = [];
        $failed  = [];

        foreach (array_chunk($files, $this->concurrency) as $batch) {
            $pending = array_map(fn($f) => ['file' => $f, 'attempt' => 0], $batch);

            while (!empty($pending)) {
                [$batchSuccess, $batchFailed, $retry] = $this->processBatch($pending, $accessToken);

                // Use + operator to preserve numeric entity_id keys
                $success = $success + $batchSuccess;
                $failed  = array_merge($failed, $batchFailed);
                $pending = $retry;
            }
        }

        return [$success, $failed];
    }

    /**
     * Execute one round of concurrent cURL downloads.
     * Returns [ $success[], $failed[], $retry[] ]
     */
    private function processBatch(array $pending, string $accessToken): array
    {
        [$mh, $handles] = $this->initCurlMultiHandles($pending, $accessToken);

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        // Remove easy handles BEFORE collecting results so releasing them
        // does not conflict with curl_multi_close() still holding references.
        foreach ($handles as $h) {
            if ($h['ch'] !== null) {
                curl_multi_remove_handle($mh, $h['ch']);
            }
        }

        $result = $this->collectBatchResults($pending, $handles);

        curl_multi_close($mh);

        return $result;
    }

    /**
     * Initialise a cURL multi handle and attach one easy handle per file.
     * Returns [ $multiHandle, $handles[] ]
     */
    private function initCurlMultiHandles(array $pending, string $accessToken): array
    {
        $mh = curl_multi_init();
        curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, $this->concurrency);

        $handles = [];

        foreach ($pending as $i => $item) {
            $fh = fopen($item['file']['save_path'], 'wb');

            if (!$fh) {
                $handles[$i] = ['ch' => null, 'fh' => null];
                continue;
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $item['file']['api_url'],
                CURLOPT_FILE           => $fh,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_FOLLOWLOCATION => true,
                // false: HTTP errors come back as errno 0 + status code, so a 4xx
                // fails at once instead of burning retries.
                CURLOPT_FAILONERROR    => false,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
            ]);

            curl_multi_add_handle($mh, $ch);
            $handles[$i] = ['ch' => $ch, 'fh' => $fh];
        }

        return [$mh, $handles];
    }

    /**
     * Inspect each handle result after the batch finishes.
     * Returns [ $success[], $failed[], $retry[] ]
     */
    private function collectBatchResults(array $pending, array $handles): array
    {
        $success = [];
        $failed  = [];
        $retry   = [];

        foreach ($handles as $i => $handle) {
            $item     = $pending[$i];
            $attempt  = $item['attempt'] + 1;
            $filename = $item['file']['filename'] ?? basename($item['file']['object']);

            // Could not open the save path
            if ($handle['ch'] === null) {
                $failed[] = [
                    'file'      => $item['file']['object'],
                    'error'     => 'Cannot open save path',
                    'entity_id' => $item['file']['entity_id'],
                ];
                $this->log(
                    'Failed to open save path for: ' . $filename . ' (entity_id: ' . $item['file']['entity_id'] . ')',
                    'error', 'error', true
                );
                continue;
            }

            $errno    = curl_errno($handle['ch']);
            $error    = curl_error($handle['ch']);
            $httpCode = curl_getinfo($handle['ch'], CURLINFO_HTTP_CODE);

            fclose($handle['fh']);
            // No curl_close(): deprecated in PHP 8.5, no-op since 8.0. The handle was
            // already detached from the multi handle above and is freed with $handles.
            unset($handle['ch'], $handles[$i]['ch']);

            if ($errno === 0 && $httpCode === 200) {
                $success[$item['file']['entity_id']] = $item['file']['object'];
                $this->log(
                    'Downloaded: ' . $filename . ' (entity_id: ' . $item['file']['entity_id'] . ')',
                    'info', 'info', true
                );

            } elseif ($errno === 0 && $this->isPermanentHttpError($httpCode)) {
                @unlink($item['file']['save_path']);
                $this->log(
                    'GCS error HTTP ' . $httpCode . ': ' . $filename . ' (entity_id: ' . $item['file']['entity_id'] . ')',
                    'error', 'error', true
                );
                $failed[] = [
                    'file'      => $item['file']['object'],
                    'error'     => 'GCS returned HTTP ' . $httpCode . ' — image not found or access denied',
                    'entity_id' => $item['file']['entity_id'],
                ];

            } elseif ($attempt < $this->maxRetries) {
                @unlink($item['file']['save_path']);
                $this->log(
                    'Retrying (' . $attempt . '/' . ($this->maxRetries - 1) . '): ' . $filename . ' — ' . $error . ' (HTTP ' . $httpCode . ') (entity_id: ' . $item['file']['entity_id'] . ')',
                    'warning', 'comment', true
                );
                sleep((int) pow(2, $attempt - 1));
                $retry[] = ['file' => $item['file'], 'attempt' => $attempt];

            } else {
                @unlink($item['file']['save_path']);
                $this->log(
                    'Failed after ' . $this->maxRetries . ' attempts: ' . $filename . ' — ' . $error . ' (HTTP ' . $httpCode . ') (entity_id: ' . $item['file']['entity_id'] . ')',
                    'error', 'error', true
                );
                $failed[] = [
                    'file'      => $item['file']['object'],
                    'error'     => "$error (HTTP $httpCode)",
                    'entity_id' => $item['file']['entity_id'],
                ];
            }
        }

        return [$success, $failed, $retry];
    }

    /**
     * 4xx = bad path / no access, retrying never helps. 408 and 429 are transient.
     */
    private function isPermanentHttpError(int $httpCode): bool
    {
        return $httpCode >= 400 && $httpCode < 500 && !in_array($httpCode, [408, 429], true);
    }

    /**
     * Persist download outcomes (success & failed) back to the queue table.
     */
    private function persistDownloadResults(array $success, array $failed): void
    {
        $this->markAsDownloaded($success);
        $this->markAsFailed($failed);
    }

    /**
     * Reset any records stuck in 'processing' state back to 'pending'.
     */
    private function resetStuckProcessingRecords(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName  = $this->resourceConnection->getTableName(self::PHOTO_STUDIO_BUCKET_IMAGES_TABLE);

        $connection->update(
            $tableName,
            ['status' => self::STATUS_PENDING, 'updated_at' => (new DateTime())->format('Y-m-d H:i:s')],
            ['status = ?' => self::STATUS_PROCESSING]
        );
    }

    /**
     * Mark records as 'processing' so we can track in-flight downloads.
     */
    private function markAsProcessing(array $entityIds): void
    {
        if (empty($entityIds)) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $tableName  = $this->resourceConnection->getTableName(self::PHOTO_STUDIO_BUCKET_IMAGES_TABLE);

        $connection->update(
            $tableName,
            ['status' => self::STATUS_PROCESSING, 'updated_at' => (new DateTime())->format('Y-m-d H:i:s')],
            ['entity_id IN (?)' => $entityIds]
        );
    }

    /**
     * Mark successfully downloaded records as 'downloaded'.
     */
    private function markAsDownloaded(array $success): void
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName  = $this->resourceConnection->getTableName(self::PHOTO_STUDIO_BUCKET_IMAGES_TABLE);
        $now        = (new DateTime())->format('Y-m-d H:i:s');

        foreach ($success as $entityId => $object) {
            $connection->update(
                $tableName,
                ['status' => self::STATUS_DOWNLOADED, 'updated_at' => $now],
                ['entity_id = ?' => $entityId]
            );
        }
    }

    /**
     * Mark failed records with status 'failed' and the error message.
     */
    private function markAsFailed(array $failed): void
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName  = $this->resourceConnection->getTableName(self::PHOTO_STUDIO_BUCKET_IMAGES_TABLE);
        $now        = (new DateTime())->format('Y-m-d H:i:s');

        foreach ($failed as $fail) {
            if (empty($fail['entity_id'])) {
                continue;
            }
            $connection->update(
                $tableName,
                [
                    'status'        => self::STATUS_FAILED,
                    'updated_at'    => $now,
                    'error_message' => $fail['error'],
                ],
                ['entity_id = ?' => $fail['entity_id']]
            );
        }
    }

    /**
     * Unified logger — writes to file and/or CLI output.
     */
    private function log(
        string $message,
        string $level           = 'info',
        string $commandLogLevel = 'info',
        bool   $saveFileLog     = false
    ): void {
        if ($saveFileLog) {
            $this->logger->writeDaily($message, $level);
        }

        if ($this->commandLineLog) {
            $this->commandLineLog->writeln("<$commandLogLevel>$message</$commandLogLevel>");
        }
    }

    /**
     * Human-readable elapsed time since $start (microtime).
     */
    public function elapsed(float $start): string
    {
        $seconds = microtime(true) - $start;

        return $seconds >= 60
            ? sprintf('%dm %ds', floor($seconds / 60), $seconds % 60)
            : sprintf('%.2fs', $seconds);
    }
}
