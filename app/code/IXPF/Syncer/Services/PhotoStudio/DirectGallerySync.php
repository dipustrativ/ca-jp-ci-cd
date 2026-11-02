<?php

declare(strict_types=1);

namespace IXPF\Syncer\Services\PhotoStudio;

use DateTime;
use Exception;
use IXPF\Syncer\Helper\Helpers as ProductHelper;
use IXPF\Syncer\Helper\Logger\PhotoStudioLogger;
use IXPF\Syncer\Helper\PhotoStudio;
use Magento\Framework\App\ResourceConnection;
use Symfony\Component\Console\Output\OutputInterface;

class DirectGallerySync
{
    // =========================================================================
    // CONSTANTS
    // =========================================================================

    const PHOTO_STUDIO_QUEUE_TABLE = 'ixpf_photo_studio_queue';
    const PHOTO_STUDIO_BUCKET_IMAGES_TABLE = 'ixpf_photo_studio_bucket_images';

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_DOWNLOADED = 'downloaded';
    const STATUS_FAILED = 'failed';
    const STATUS_MAGENTO_FAILED = 'failed_by_magento';

    const STATUS_COMPLETED = 'completed';
    const STATUS_READY_FOR_WEBHOOK = 'ready_for_webhook';
    const STATUS_SUPERSEDED = 'superseded';

    const DEFAULT_IMAGE_ROLES = ['image', 'small_image', 'thumbnail'];

    /** status_code reported to IXPF in response_result. */
    const RESPONSE_CODE_SUCCESS = 200;
    const RESPONSE_CODE_FAILED = 500;

    /**
     * EAV attribute_id → role name map.
     *
     * Verify these IDs match your instance:
     *   SELECT attribute_id, attribute_code FROM eav_attribute
     *   WHERE entity_type_id = 4
     *   AND attribute_code IN ('image','small_image','thumbnail','swatch_image');
     */
    const ROLE_ATTRIBUTE_CODES = ['image', 'small_image', 'thumbnail', 'swatch_image'];

    /** @var array<int, string>|null Resolved attribute_id → role name map (lazy). */
    private ?array $roleAttributeMap = null;

    /**
     * Minutes a queue row must be stuck in 'processing' before it is
     * eligible for automatic reset back to 'pending'.
     */
    const STUCK_PROCESSING_THRESHOLD_MINUTES = 15;

    /** Value of the `media_type` column in catalog_product_entity_media_gallery. */
    const MEDIA_IMAGE_TYPE = 'image';

    /** @var OutputInterface|null Active Symfony console output, set per process() call. */
    private ?OutputInterface $consoleOutput = null;

    public function __construct(
        protected ResourceConnection         $resourceConnection,
        protected PhotoStudio                $photoStudioHelper,
        protected ProductHelper              $productHelper,
        protected readonly PhotoStudioLogger $logger,
    )
    {
    }

    /**
     * Main entry point — called by the cron job or CLI command.
     *
     * Resets any rows stuck in 'processing' from a previous crashed run,
     * fetches the next batch of pending queue jobs, locks them, and processes
     * each one end-to-end via raw SQL gallery writes.
     *
     * @param int $limit Maximum number of queue batches to process per run.
     * @param OutputInterface|null $consoleOutput Symfony console output; null when run via cron.
     * @return bool False when at least one batch finished with errors.
     */
    public function process(int $limit = 25, ?OutputInterface $consoleOutput = null): bool
    {
        $this->consoleOutput = $consoleOutput;
        $this->logInfo(sprintf('DirectGallerySync: Starting — limit=%d', $limit), false);

        $this->resetStuckProcessingRows();
        $this->supersedeOlderPendingJobs();

        $batches = $this->getNextBatches($limit);
        if (empty($batches)) {
            $this->logInfo('DirectGallerySync: No pending batches found, exiting.', false);
            return true;
        }

        $this->logInfo(sprintf('DirectGallerySync: Found %d batch(es) to process.', count($batches)), false);
        $this->markBatchesAsProcessing($batches);

        $allSucceeded = true;
        foreach ($batches as $batch) {
            $queueId = (int)$batch['queue']['entity_id'];
            $productId = (int)$batch['queue']['object_id'];
            $this->logInfo(sprintf(
                'DirectGallerySync: Processing queue ID=%d | product ID=%d', $queueId, $productId
            ), false);
            if (!$this->processBatch($batch)) {
                $allSucceeded = false;
            }
            $this->logInfo('-----------------------------------------------------', false);
        }

        $this->logInfo('DirectGallerySync: Run complete.', false);

        return $allSucceeded;
    }

    /**
     * Fetch the next $limit pending queue jobs where ALL related bucket images
     * reached a final download state — 'downloaded' or 'failed' — (or there are
     * no bucket images at all). Failed downloads are reported back to IXPF as
     * upload errors instead of leaving the queue job pending forever.
     *
     * @param int $limit Maximum number of queue rows to return.
     * @return array<int, array{queue: array<string, mixed>, images: array<int, array<string, mixed>>}>
     */
    public function getNextBatches(int $limit = 25): array
    {
        $conn = $this->resourceConnection->getConnection();
        $queueTable = $this->resourceConnection->getTableName(self::PHOTO_STUDIO_QUEUE_TABLE);
        $bucketTable = $this->resourceConnection->getTableName(self::PHOTO_STUDIO_BUCKET_IMAGES_TABLE);

        $select = $conn->select()
            ->from(['q' => $queueTable], ['q.*'])
            ->joinLeft(['bi' => $bucketTable], 'bi.photo_studio_queue_id = q.entity_id', [])
            ->where('q.status = ?', self::STATUS_PENDING)
            ->group('q.entity_id')
            ->having($conn->quoteInto(
                'COUNT(bi.entity_id) = 0 OR COUNT(bi.entity_id) = SUM(bi.status IN (?))',
                [self::STATUS_DOWNLOADED, self::STATUS_FAILED]
            ))
            ->order('q.created_at ASC')
            ->limit($limit);

        $queueRows = $conn->fetchAll($select);
        if (empty($queueRows)) {
            return [];
        }

        $queueIds = array_column($queueRows, 'entity_id');
        $allImages = $conn->fetchAll(
            $conn->select()
                ->from(['bi' => $bucketTable])
                ->where('bi.photo_studio_queue_id IN (?)', $queueIds)
        );

        $imagesByQueueId = [];
        foreach ($allImages as $img) {
            $imagesByQueueId[$img['photo_studio_queue_id']][] = $img;
        }

        $batches = [];
        foreach ($queueRows as $queue) {
            $batches[] = [
                'queue' => $queue,
                'images' => $imagesByQueueId[$queue['entity_id']] ?? [],
            ];
        }

        return $batches;
    }


    /**
     * Process a single queue batch end-to-end.
     *
     * Steps:
     *   1. Decode & validate payload JSON.
     *   2. Resolve the product's entity_id / row_id / sku from the DB.
     *   3. Empty payload → delete all images and mark ready_for_webhook.
     *   4. Classify payload items into new / existing / keep-ids. Any existing id not in the
     *      product's gallery (stale payload) rejects the job with the gallery left unchanged.
     *   5. Delete gallery entries no longer referenced by the payload.
     *   6. Copy new image files into pub/media and insert gallery rows.
     *   7. Update metadata (label, position, disabled, roles) for existing entries.
     *   8. Persist final status and response_result to the queue row.
     *
     * Any error (failed download, missing/corrupt file, DB error) marks the
     * result as success=false / status_code=500. The job still goes to
     * ready_for_webhook so IXPF is notified.
     *
     * @param array{queue: array<string, mixed>, images: array<int, array<string, mixed>>} $batch
     * @return bool True when the batch finished without any error.
     */
    private function processBatch(array $batch): bool
    {
        $queue = $batch['queue'];
        $queueId = (int)$queue['entity_id'];
        $productId = (int)$queue['object_id'];

        $payload = json_decode($queue['payload'], true);
        if (!is_array($payload)) {
            $this->logError(sprintf('DirectGallerySync: Queue ID=%d — Invalid payload JSON.', $queueId));
            $this->failQueue($queueId, ['Invalid payload JSON (could not decode).']);
            return false;
        }

        $productRow = $this->getProductRow($productId);
        if ($productRow === null) {
            $this->logError(sprintf('DirectGallerySync: Queue ID=%d — Product ID=%d not found.', $queueId, $productId));
            $this->failQueue($queueId, ['Product not found: ID=' . $productId]);
            return false;
        }

        if (empty($payload)) {
            $this->logInfo(sprintf(
                'DirectGallerySync: Queue ID=%d — Empty payload, removing all images from product ID=%d.',
                $queueId, $productId
            ));
            $deleteErrors = $this->deleteAllImages($productRow);
            return $this->completeQueue($queueId, [], [], $deleteErrors);
        }

        [$newImages, $existingImages, $keepValueIds] = $this->classifyPayloadItems($payload);

        // The payload is built from value_ids IXPF got in an earlier webhook. If one is no longer
        // in this product's gallery the payload is stale: deleteRemovedImages() would drop every
        // real image (none are in $keepValueIds), so reject the job before touching anything and
        // report the current gallery for IXPF to resync from.
        $unknownIds = array_values(array_diff(
            array_filter($keepValueIds),
            $this->getProductValueIds($productRow)
        ));
        if (!empty($unknownIds)) {
            $this->logWarning(sprintf(
                'DirectGallerySync: Queue ID=%d — value_id(s) %s not in product ID=%d gallery; payload rejected, gallery unchanged.',
                $queueId, implode(', ', $unknownIds), $productId
            ));
            return $this->completeQueue(
                $queueId,
                $this->photoStudioHelper->getGalleryImages($productId),
                [],
                array_map(
                    fn($id) => 'value_id ' . $id . ' not found in this product\'s gallery'
                        . ' (stale or wrong id) — payload rejected, gallery unchanged.',
                    $unknownIds
                )
            );
        }

        $failedDownloads = $this->getFailedDownloads($batch['images']);

        // Must run before deleteRemovedImages(): a resent image keeps its value_id instead of
        // being deleted and re-inserted under a new one.
        [$newImages, $existingImages, $keepValueIds] = $this->reuseIdenticalImages(
            $productRow,
            $newImages,
            $existingImages,
            $keepValueIds,
            $failedDownloads
        );

        $this->logDebug(sprintf(
            'DirectGallerySync: Queue ID=%d | new=%d existing=%d keepIds=%s',
            $queueId, count($newImages), count($existingImages),
            implode(',', $keepValueIds) ?: 'none'
        ));

        $uploadErrors = [];
        $otherErrors = [];

        $otherErrors = array_merge($otherErrors, $this->deleteRemovedImages($productRow, $keepValueIds));
        [$addErrors, $addUploadErrors] = $this->addNewImages($productRow, $newImages, $failedDownloads);
        $otherErrors = array_merge($otherErrors, $addErrors);
        $uploadErrors = array_merge($uploadErrors, $addUploadErrors);
        $otherErrors = array_merge($otherErrors, $this->updateExistingImages($productRow, $existingImages));

        $this->logInfo(sprintf(
            'DirectGallerySync: Queue ID=%d | Product ID=%d — Done. upload_errors=%d others_errors=%d',
            $queueId, $productId, count($uploadErrors), count($otherErrors)
        ));

        return $this->completeQueue(
            $queueId,
            $this->photoStudioHelper->getGalleryImages($productId),
            $uploadErrors,
            $otherErrors
        );
    }

    /**
     * Map bucket_image_path → error_message for the batch's failed downloads.
     *
     * @param array<int, array<string, mixed>> $images Rows from ixpf_photo_studio_bucket_images.
     * @return array<string, string>
     */
    private function getFailedDownloads(array $images): array
    {
        $failed = [];
        foreach ($images as $image) {
            if (($image['status'] ?? '') === self::STATUS_FAILED) {
                $failed[(string)$image['bucket_image_path']] = (string)($image['error_message'] ?? '') ?: 'Download failed';
            }
        }

        return $failed;
    }


    /**
     * Match new_image items against the images already in the product's gallery (and against
     * each other) by file content, so a resent or repeated photo is not added again.
     *
     * - Same content as a current gallery image → becomes an update of that value_id (label,
     *   position, disabled, roles) and the value_id is kept, not deleted and re-inserted.
     *   If the payload already lists that value_id, the explicit entry wins.
     * - Same content as an earlier new_image in this payload → dropped.
     *
     * Items whose download failed or whose file is missing are left for addNewImages() to report.
     *
     * @param array{entity_id: int, row_id: int, sku: string} $productRow
     * @param array<int, array<string, mixed>> $newImages
     * @param array<int, array<string, mixed>> $existingImages
     * @param int[] $keepValueIds
     * @param array<string, string> $failedDownloads
     * @return array{0: array, 1: array, 2: int[]}
     */
    private function reuseIdenticalImages(
        array $productRow,
        array $newImages,
        array $existingImages,
        array $keepValueIds,
        array $failedDownloads
    ): array {
        if (empty($newImages)) {
            return [$newImages, $existingImages, $keepValueIds];
        }

        $downloadDir = $this->productHelper->getGoogleBucketImageInitDownloadPath();
        $galleryHashes = $this->getGalleryFileHashes($productRow);
        $remaining = [];
        $remainingHashes = [];
        $resolvedPaths = [];

        foreach ($newImages as $item) {
            $bucketUrl = (string)($item['bucket_url'] ?? '');
            $sourcePath = $downloadDir . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($bucketUrl));

            if (isset($failedDownloads[$bucketUrl]) || !is_file($sourcePath) || filesize($sourcePath) === 0) {
                $remaining[] = $item;
                continue;
            }

            $hash = hash_file('sha256', $sourcePath);
            $valueId = $hash !== false ? array_search($hash, $galleryHashes, true) : false;

            if ($valueId !== false) {
                if (!in_array($valueId, $keepValueIds, true)) {
                    $existingImages[] = array_merge($item, ['id' => $valueId]);
                    $keepValueIds[] = $valueId;
                }
                $resolvedPaths[] = $sourcePath;
                $this->logInfo(sprintf(
                    'DirectGallerySync: %s is identical to gallery value_id=%d — kept, not re-added.',
                    basename($sourcePath),
                    $valueId
                ));
                continue;
            }

            if ($hash !== false && in_array($hash, $remainingHashes, true)) {
                $resolvedPaths[] = $sourcePath;
                $this->logInfo(sprintf(
                    'DirectGallerySync: %s repeats an image earlier in the payload — skipped.',
                    basename($sourcePath)
                ));
                continue;
            }

            $remaining[] = $item;
            $remainingHashes[] = $hash;
        }

        // Temp files no longer needed — unless a remaining item still uploads the same file.
        $stillNeeded = [];
        foreach ($remaining as $item) {
            $stillNeeded[] = $downloadDir
                . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename((string)($item['bucket_url'] ?? '')));
        }
        foreach (array_diff(array_unique($resolvedPaths), $stillNeeded) as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        return [$remaining, $existingImages, $keepValueIds];
    }

    /**
     * Content hash of every image file currently in the product's gallery.
     *
     * @param array{entity_id: int, row_id: int, sku: string} $productRow
     * @return array<int, string> value_id => sha256 (files missing on disk are left out)
     */
    private function getGalleryFileHashes(array $productRow): array
    {
        $conn = $this->resourceConnection->getConnection();
        $galleryT = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery');
        $valueToET = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value_to_entity');

        $files = $conn->fetchPairs(
            $conn->select()
                ->from(['g' => $galleryT], ['value_id', 'value'])
                ->join(['vte' => $valueToET], 'vte.value_id = g.value_id', [])
                ->where('vte.entity_id = ?', (int)$productRow['entity_id'])
                ->where('g.media_type = ?', self::MEDIA_IMAGE_TYPE)
        );

        $mediaBaseDir = $this->getMediaBaseDir();
        $hashes = [];
        foreach ($files as $valueId => $file) {
            $path = $mediaBaseDir . '/' . ltrim((string)$file, '/');
            if (is_file($path)) {
                $hash = hash_file('sha256', $path);
                if ($hash !== false) {
                    $hashes[(int)$valueId] = $hash;
                }
            }
        }

        return $hashes;
    }

    /**
     * Every gallery value_id currently linked to the product.
     *
     * @param array{entity_id: int, row_id: int, sku: string} $productRow
     * @return int[]
     */
    private function getProductValueIds(array $productRow): array
    {
        $conn = $this->resourceConnection->getConnection();
        $valueToET = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value_to_entity');

        return array_map('intval', $conn->fetchCol(
            "SELECT value_id FROM {$valueToET} WHERE entity_id = ?",
            [(int)$productRow['entity_id']]
        ));
    }

    /**
     * Fetch the minimal product data needed for raw SQL gallery writes.
     *
     * Returns an associative array with keys entity_id, row_id, and sku,
     * or null if the product does not exist.
     *
     * The row_id distinction matters for Magento Commerce (EE) instances with
     * content staging: the gallery value tables store the sequence-table PK
     * (row_id) in their entity_id column, not the catalog entity_id. On
     * Community Edition the two values are always equal.
     *
     * @param int $productId catalog_product_entity.entity_id
     * @return array{entity_id: int, row_id: int, sku: string}|null
     */
    private function getProductRow(int $productId): ?array
    {
        $conn = $this->resourceConnection->getConnection();
        $seqTable = $this->resourceConnection->getTableName('sequence_product');
        $entTable = $this->resourceConnection->getTableName('catalog_product_entity');

        if ($conn->isTableExists($seqTable)) {
            $row = $conn->fetchRow(
                "SELECT e.entity_id, e.row_id, e.sku FROM {$entTable} e WHERE e.entity_id = ?",
                [$productId]
            );
        } else {
            $row = $conn->fetchRow(
                "SELECT entity_id, entity_id AS row_id, sku FROM {$entTable} WHERE entity_id = ?",
                [$productId]
            );
        }

        return $row ?: null;
    }


    /**
     * Split a decoded payload array into three groups:
     *
     *   - $newImages      — items with id = 'new_image'; files to be copied and inserted.
     *   - $existingImages — items with a numeric id; metadata to be updated in place.
     *   - $keepValueIds   — the value_ids that must NOT be deleted from the gallery.
     *
     * Items with id = '' or id = 'tmp' are skipped (incomplete uploads).
     *
     * @param array<int, array<string, mixed>> $payload Decoded JSON payload from the queue row.
     * @return array{0: array, 1: array, 2: int[]}
     */
    private function classifyPayloadItems(array $payload): array
    {
        $newImages = [];
        $existingImages = [];
        $keepValueIds = [];

        foreach ($payload as $item) {
            $id = $item['id'] ?? '';
            if ($id === 'new_image') {
                $newImages[] = $item;
            } elseif ($id !== '' && $id !== 'tmp') {
                $existingImages[] = $item;
                $keepValueIds[] = (int)$id;
            }
        }

        return [$newImages, $existingImages, $keepValueIds];
    }


    /**
     * Remove ALL gallery images associated with a product.
     *
     * Looks up every value_id linked to the product via the
     * media_gallery_value_to_entity table and delegates to deleteValueIds().
     *
     * @param array{entity_id: int, row_id: int, sku: string} $productRow
     * @return string[] List of error messages for any value_ids that failed.
     */
    private function deleteAllImages(array $productRow): array
    {
        $valueIds = $this->getProductValueIds($productRow);

        return empty($valueIds) ? [] : $this->deleteValueIds($productRow, $valueIds);
    }

    /**
     * Delete every gallery image for a product whose value_id is NOT in $keepValueIds.
     *
     * When $keepValueIds is empty every linked image is removed (equivalent to
     * deleteAllImages, but called from the normal payload flow).
     *
     * @param array{entity_id: int, row_id: int, sku: string} $productRow
     * @param int[] $keepValueIds value_ids to preserve.
     * @return string[] Error messages for any deletions that failed.
     */
    private function deleteRemovedImages(array $productRow, array $keepValueIds): array
    {
        $conn = $this->resourceConnection->getConnection();
        $valueToET = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value_to_entity');

        $select = $conn->select()
            ->from($valueToET, ['value_id'])
            ->where('entity_id = ?', (int)$productRow['entity_id']);

        if (!empty($keepValueIds)) {
            $select->where('value_id NOT IN (?)', $keepValueIds);
        }

        $toRemove = $conn->fetchCol($select);

        if (empty($toRemove)) {
            $this->logDebug('DirectGallerySync: deleteRemovedImages — nothing to remove.');
            return [];
        }

        $this->logInfo(sprintf('DirectGallerySync: Removing value_ids: %s', implode(', ', $toRemove)));

        return $this->deleteValueIds($productRow, $toRemove);
    }

    /**
     * Core delete routine: removes the given value_ids from all four gallery tables.
     *
     * Deletion order:
     *   1. catalog_product_entity_media_gallery_value       — per-store rows
     *   2. catalog_product_entity_media_gallery_value_to_entity — product linkage
     *   3. catalog_product_entity_media_gallery             — master row (only if
     *      no other products still reference this value_id)
     *   4. catalog_product_entity_varchar                   — role EAV attributes
     *      whose value matches the deleted file path
     *
     * @param array{entity_id: int, row_id: int, sku: string} $productRow
     * @param int[] $valueIds
     * @return string[] Error messages for any value_ids that failed.
     */
    private function deleteValueIds(array $productRow, array $valueIds): array
    {
        $conn = $this->resourceConnection->getConnection();
        $galleryT = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery');
        $galleryVT = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value');
        $valueToET = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value_to_entity');
        $varcharT = $this->resourceConnection->getTableName('catalog_product_entity_varchar');

        $errors = [];
        $entityId = (int)$productRow['entity_id'];

        foreach ($valueIds as $valueId) {
            try {
                $filePath = $conn->fetchOne(
                    "SELECT value FROM {$galleryT} WHERE value_id = ?",
                    [$valueId]
                );

                $conn->delete($galleryVT, ['value_id = ?' => $valueId]);
                $conn->delete($valueToET, ['value_id = ?' => $valueId, 'entity_id = ?' => $entityId]);

                $remaining = (int)$conn->fetchOne(
                    "SELECT COUNT(*) FROM {$valueToET} WHERE value_id = ?",
                    [$valueId]
                );
                if ($remaining === 0) {
                    $conn->delete($galleryT, ['value_id = ?' => $valueId]);
                }

                if ($filePath) {
                    foreach (array_keys($this->getRoleAttributeMap()) as $attrId) {
                        $conn->delete($varcharT, [
                            'attribute_id = ?' => $attrId,
                            'entity_id = ?' => $entityId,
                            'value = ?' => $filePath,
                        ]);
                    }
                }

                $this->logDebug(sprintf('DirectGallerySync: Deleted value_id=%d', $valueId));

            } catch (Exception $e) {
                $msg = sprintf('Delete error value_id=%d: %s', $valueId, $e->getMessage());
                $this->logError('DirectGallerySync: ' . $msg);
                $errors[] = $msg;
            }
        }

        return $errors;
    }


    /**
     * Copy pre-downloaded image files into pub/media/catalog/product and insert
     * the corresponding gallery rows directly into the database.
     *
     * For each new image item the method:
     *   1. Validates the source file exists on disk and is a valid image.
     *   2. Copies it into Magento's dispersion path (/x/y/filename.jpg).
     *   3. Resolves which gallery roles to assign.
     *   4. Inserts rows across all four gallery tables via insertGalleryRows().
     *   5. Deletes the temporary downloaded file on success.
     *
     * @param array{entity_id: int, row_id: int, sku: string} $productRow
     * @param array<int, array<string, mixed>> $newImages Items classified as 'new_image'.
     * @param array<string, string> $failedDownloads bucket_image_path → download error message.
     * @return array{0: string[], 1: array[]}  [$otherErrors, $uploadErrors]
     */
    private function addNewImages(array $productRow, array $newImages, array $failedDownloads = []): array
    {
        if (empty($newImages)) {
            return [[], []];
        }

        $downloadDir = $this->productHelper->getGoogleBucketImageInitDownloadPath();
        $mediaBaseDir = $this->getMediaBaseDir();
        $takenRoles = $this->getCurrentRoles($productRow);
        $otherErrors = [];
        $uploadErrors = [];

        foreach ($newImages as $item) {
            $bucketUrl = (string)($item['bucket_url'] ?? '');
            // Same sanitising as ProcessGoogleBucketImages::sanitizeFilename() so the paths match.
            $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($bucketUrl));
            $sourcePath = $downloadDir . $filename;

            if (isset($failedDownloads[$bucketUrl])) {
                $msg = sprintf('Download failed, skipped: %s (%s)', $filename, $failedDownloads[$bucketUrl]);
                $this->logWarning('DirectGallerySync: ' . $msg);
                $uploadErrors[] = array_merge($item, ['error' => $msg]);
                $otherErrors[] = $msg;
                continue;
            }

            if (!file_exists($sourcePath) || filesize($sourcePath) === 0) {
                $msg = 'File not found on disk, skipped: ' . $filename;
                $this->logWarning('DirectGallerySync: ' . $msg);
                $uploadErrors[] = array_merge($item, ['error' => $msg]);
                $otherErrors[] = $msg;
                continue;
            }

            if (function_exists('imagecreatefromstring')) {
                $img = @imagecreatefromstring(file_get_contents($sourcePath));
                if ($img === false) {
                    $msg = 'Image corrupted, skipped: ' . $filename;
                    $this->logWarning('DirectGallerySync: ' . $msg);
                    $uploadErrors[] = array_merge($item, ['error' => $msg]);
                    $otherErrors[] = $msg;
                    continue;
                }
                unset($img);
            }

            try {
                $destRelPath = $this->disperseFile($sourcePath, $filename, $mediaBaseDir);
            } catch (Exception $e) {
                $msg = sprintf('Copy error file=%s: %s', $filename, $e->getMessage());
                $this->logError('DirectGallerySync: ' . $msg);
                $uploadErrors[] = array_merge($item, ['error' => $e->getMessage()]);
                $otherErrors[] = $msg;
                continue;
            }

            $roles = $this->resolveRoles($item['role'] ?? [], $takenRoles);
            foreach ($roles as $r) {
                $takenRoles[] = $r;
            }

            try {
                $valueId = $this->insertGalleryRows(
                    $productRow,
                    $destRelPath,
                    $item['label'] ?? '',
                    (int)($item['position'] ?? 0),
                    (bool)($item['disabled'] ?? false),
                    $roles
                );

                $this->logDebug(sprintf(
                    'DirectGallerySync: Inserted value_id=%d file=%s roles=%s',
                    $valueId, $destRelPath, implode(',', $roles)
                ));

                @unlink($sourcePath);

            } catch (Exception $e) {
                $msg = sprintf('Gallery insert error file=%s: %s', $filename, $e->getMessage());
                $this->logError('DirectGallerySync: ' . $msg);
                $uploadErrors[] = array_merge($item, ['error' => $e->getMessage()]);
                $otherErrors[] = $msg;
            }
        }

        return [$otherErrors, $uploadErrors];
    }

    /**
     * Copy a source file into pub/media/catalog/product using Magento's standard
     * two-level dispersion path convention: /firstchar/secondchar/filename.jpg.
     *
     * If a file with the same name already exists at the destination, a short
     * random suffix is appended to avoid overwriting unrelated images.
     *
     * @param string $sourcePath Absolute path to the temporary downloaded file.
     * @param string $filename Original base filename (URL-sourced; sanitised internally).
     * @param string $mediaBaseDir Absolute path to pub/media/catalog/product.
     * @return string               Relative path stored in the gallery table, e.g. /a/b/file.jpg.
     *
     * @throws \RuntimeException If the destination directory cannot be created or copy() fails.
     */
    private function disperseFile(string $sourcePath, string $filename, string $mediaBaseDir): string
    {
        $filename = preg_replace('/[?#].*$/', '', $filename);
        $filename = preg_replace('/[^\w.\-]/', '_', $filename);

        $char1 = strtolower($filename[0] ?? 'a');
        $char2 = strtolower($filename[1] ?? 'a');
        $subDir = $mediaBaseDir . DIRECTORY_SEPARATOR . $char1 . DIRECTORY_SEPARATOR . $char2;

        if (!is_dir($subDir) && !mkdir($subDir, 0775, true) && !is_dir($subDir)) {
            throw new \RuntimeException('Cannot create directory: ' . $subDir);
        }

        $destPath = $subDir . DIRECTORY_SEPARATOR . $filename;
        $destRelPath = '/' . $char1 . '/' . $char2 . '/' . $filename;

        if (file_exists($destPath)) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $base = pathinfo($filename, PATHINFO_FILENAME);
            $suffix = '_' . substr(md5(microtime()), 0, 6);
            $filename = $base . $suffix . ($ext ? '.' . $ext : '');
            $destPath = $subDir . DIRECTORY_SEPARATOR . $filename;
            $destRelPath = '/' . $char1 . '/' . $char2 . '/' . $filename;
        }

        if (!copy($sourcePath, $destPath)) {
            throw new \RuntimeException('copy() failed: ' . $sourcePath . ' → ' . $destPath);
        }

        return $destRelPath;
    }

    /**
     * Insert one complete gallery image record across all four tables in the
     * correct order for Magento to recognise the image on the next page load.
     *
     * Write order:
     *   1. catalog_product_entity_media_gallery          — master row; yields value_id.
     *   2. catalog_product_entity_media_gallery_value    — per-store row (store_id=0).
     *   3. catalog_product_entity_media_gallery_value_to_entity — product linkage.
     *   4. catalog_product_entity_varchar                — role EAV attributes.
     *
     * The media_gallery attribute_id is resolved once per process run and cached
     * in a static local to avoid a repeated lookup on every image.
     *
     * @param array{entity_id: int, row_id: int, sku: string} $productRow
     * @param string $filePath Relative path, e.g. /a/b/image.jpg.
     * @param string $label Alt-text / image label.
     * @param int $position Sort order in the gallery.
     * @param bool $disabled Whether the image is hidden from the storefront.
     * @param string[] $roles Gallery role names, e.g. ['image', 'small_image'].
     * @return int                 The newly created value_id.
     */
    private function insertGalleryRows(
        array  $productRow,
        string $filePath,
        string $label,
        int    $position,
        bool   $disabled,
        array  $roles
    ): int
    {
        $conn = $this->resourceConnection->getConnection();
        $galleryT = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery');
        $galleryVT = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value');
        $valueToET = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value_to_entity');
        $varcharT = $this->resourceConnection->getTableName('catalog_product_entity_varchar');

        $entityId = (int)$productRow['entity_id'];
        $rowId = (int)$productRow['row_id'];

        static $mediaAttrId = null;
        if ($mediaAttrId === null) {
            $eavT = $this->resourceConnection->getTableName('eav_attribute');
            $eavTypeT = $this->resourceConnection->getTableName('eav_entity_type');
            $mediaAttrId = (int)$conn->fetchOne(
                "SELECT a.attribute_id FROM {$eavT} a
                 JOIN {$eavTypeT} t ON t.entity_type_id = a.entity_type_id
                 WHERE t.entity_type_code = 'catalog_product'
                   AND a.attribute_code   = 'media_gallery'"
            );
        }

        $conn->insert($galleryT, [
            'attribute_id' => $mediaAttrId,
            'value' => $filePath,
            'media_type' => self::MEDIA_IMAGE_TYPE,
            'disabled' => 0,
        ]);
        $valueId = (int)$conn->lastInsertId();

        $conn->insert($galleryVT, [
            'value_id' => $valueId,
            'store_id' => 0,
            'entity_id' => $rowId,
            'label' => $label,
            'position' => $position,
            'disabled' => (int)$disabled,
        ]);

        $conn->insert($valueToET, [
            'value_id' => $valueId,
            'entity_id' => $entityId,
        ]);

        $this->upsertRoleAttributes($conn, $varcharT, $entityId, $rowId, $filePath, $roles);

        return $valueId;
    }

    /**
     * Update label, position, disabled flag, and role assignments for existing
     * gallery entries without re-uploading or moving any files.
     *
     * For each item the per-store value row (store_id=0) is upserted and the
     * master disabled flag is kept in sync. Role varchar attributes are only
     * rewritten when the payload supplies a non-empty role array.
     *
     * @param array{entity_id: int, row_id: int, sku: string} $productRow
     * @param array<int, array<string, mixed>> $existingImages Items with a numeric id.
     * @return string[] Error messages for any items that failed.
     */
    private function updateExistingImages(array $productRow, array $existingImages): array
    {
        if (empty($existingImages)) {
            return [];
        }

        $conn = $this->resourceConnection->getConnection();
        $galleryT = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery');
        $galleryVT = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value');
        $valueToET = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value_to_entity');
        $varcharT = $this->resourceConnection->getTableName('catalog_product_entity_varchar');

        $entityId = (int)$productRow['entity_id'];
        $rowId = (int)$productRow['row_id'];
        $errors = [];

        foreach ($existingImages as $item) {
            $valueId = (int)($item['id'] ?? 0);
            if ($valueId === 0) {
                continue;
            }

            try {
                // Scoped to this product: a value_id of another product's image must not be updated.
                $filePath = $conn->fetchOne(
                    "SELECT g.value FROM {$galleryT} g"
                    . " JOIN {$valueToET} vte ON vte.value_id = g.value_id"
                    . " WHERE g.value_id = ? AND vte.entity_id = ?",
                    [$valueId, $entityId]
                );

                if (!$filePath) {
                    $errors[] = 'value_id ' . $valueId . ' not found in this product\'s gallery.';
                    continue;
                }

                $conn->update($galleryT,
                    ['disabled' => (int)($item['disabled'] ?? false)],
                    ['value_id = ?' => $valueId]
                );

                $valData = [
                    'label' => $item['label'] ?? '',
                    'position' => (int)($item['position'] ?? 0),
                    'disabled' => (int)($item['disabled'] ?? false),
                ];

                $existsInStore = $conn->fetchOne(
                    "SELECT value_id FROM {$galleryVT} WHERE value_id = ? AND store_id = 0",
                    [$valueId]
                );

                if ($existsInStore) {
                    $conn->update($galleryVT, $valData, ['value_id = ?' => $valueId, 'store_id = ?' => 0]);
                } else {
                    $conn->insert($galleryVT, array_merge($valData, [
                        'value_id' => $valueId,
                        'store_id' => 0,
                        'entity_id' => $rowId,
                    ]));
                }

                $roles = $item['role'] ?? [];
                if (!empty($roles)) {
                    $this->upsertRoleAttributes($conn, $varcharT, $entityId, $rowId, $filePath, $roles);
                }

                $this->logDebug(sprintf(
                    'DirectGallerySync: Updated value_id=%d label="%s" position=%d',
                    $valueId, $item['label'] ?? '', (int)($item['position'] ?? 0)
                ));

            } catch (Exception $e) {
                $msg = sprintf('Update error value_id=%d: %s', $valueId, $e->getMessage());
                $this->logError('DirectGallerySync: ' . $msg);
                $errors[] = $msg;
            }
        }

        return $errors;
    }

    /**
     * Return the list of role names already assigned to the product by reading
     * catalog_product_entity_varchar directly.
     *
     * Used by addNewImages() to avoid assigning the same role (e.g. 'image')
     * to more than one image in the same batch.
     *
     * @param array{entity_id: int, row_id: int, sku: string} $productRow
     * @return string[] Role names currently in use, e.g. ['image', 'small_image'].
     */
    /**
     * Resolve the attribute_id → role name map from eav_attribute for this
     * instance's catalog_product entity type. Cached for the request.
     *
     * @return array<int, string>
     */
    private function getRoleAttributeMap(): array
    {
        if ($this->roleAttributeMap !== null) {
            return $this->roleAttributeMap;
        }

        $conn = $this->resourceConnection->getConnection();
        $eavAttrT = $this->resourceConnection->getTableName('eav_attribute');
        $eavTypeT = $this->resourceConnection->getTableName('eav_entity_type');

        $rows = $conn->fetchPairs(
            "SELECT a.attribute_id, a.attribute_code
               FROM {$eavAttrT} a
               JOIN {$eavTypeT} e ON e.entity_type_id = a.entity_type_id
              WHERE e.entity_type_code = 'catalog_product'
                AND a.attribute_code IN (" . $conn->quote(self::ROLE_ATTRIBUTE_CODES) . ")"
        );

        $this->roleAttributeMap = [];
        foreach ($rows as $attrId => $code) {
            $this->roleAttributeMap[(int)$attrId] = $code;
        }

        return $this->roleAttributeMap;
    }

    private function getCurrentRoles(array $productRow): array
    {
        $conn = $this->resourceConnection->getConnection();
        $varcharT = $this->resourceConnection->getTableName('catalog_product_entity_varchar');
        $entityId = (int)$productRow['entity_id'];

        $roleMap = $this->getRoleAttributeMap();
        $rows = $conn->fetchAll(
            "SELECT attribute_id, value FROM {$varcharT}
             WHERE entity_id = ?
               AND attribute_id IN (" . implode(',', array_keys($roleMap)) . ")
               AND (value IS NOT NULL AND value != 'no_selection')",
            [$entityId]
        );

        $taken = [];
        foreach ($rows as $row) {
            $roleName = $roleMap[(int)$row['attribute_id']] ?? null;
            if ($roleName !== null) {
                $taken[] = $roleName;
            }
        }

        return $taken;
    }

    /**
     * Atomically write role file-path values into catalog_product_entity_varchar
     * using INSERT … ON DUPLICATE KEY UPDATE.
     *
     * This is safe for both CE (entity_id = entity_id) and EE (entity_id = row_id)
     * and eliminates the race condition that a two-step update→insert approach
     * would have under concurrent cron execution.
     *
     * The unique key on the varchar table is (entity_id, attribute_id, store_id),
     * so a role can only ever have one value per store per product — exactly what
     * Magento expects.
     *
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $conn
     * @param string $varcharT Resolved table name.
     * @param int $entityId catalog_product_entity.entity_id (used for role cleanup only).
     * @param int $rowId row_id on EE, equals entity_id on CE.
     * @param string $filePath Relative media path to write as the role value.
     * @param string[] $roles Role names to assign, e.g. ['image', 'thumbnail'].
     */
    private function upsertRoleAttributes(
        \Magento\Framework\DB\Adapter\AdapterInterface $conn,
        string                                         $varcharT,
        int                                            $entityId,
        int                                            $rowId,
        string                                         $filePath,
        array                                          $roles
    ): void
    {
        $roleToAttrId = array_flip($this->getRoleAttributeMap());

        foreach ($roles as $role) {
            $attrId = $roleToAttrId[$role] ?? null;
            if ($attrId === null) {
                continue;
            }

            $conn->query(
                "INSERT INTO `{$varcharT}` (`attribute_id`, `store_id`, `entity_id`, `value`)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [$attrId, 0, $rowId, $filePath]
            );
        }
    }

    /**
     * Resolve which gallery roles to assign to a new image.
     *
     * If the payload specifies roles explicitly, those are used as-is.
     * Otherwise, any DEFAULT_IMAGE_ROLES not already taken by an earlier
     * image in the same batch are returned.
     *
     * @param string[] $payloadRoles Roles declared in the payload item (may be empty).
     * @param string[] $takenRoles Roles already assigned earlier in this batch.
     * @return string[]
     */
    private function resolveRoles(array $payloadRoles, array $takenRoles): array
    {
        if (!empty($payloadRoles)) {
            return $payloadRoles;
        }

        return array_values(
            array_filter(self::DEFAULT_IMAGE_ROLES, fn($r) => !in_array($r, $takenRoles, true))
        );
    }

    /**
     * Return the absolute path to Magento's product media directory.
     *
     * BP is the Magento base-path constant available in all contexts
     * (cron, CLI, web). Adjust the segments if your pub/media location
     * is symlinked or remapped.
     *
     * @return string  e.g. /var/www/html/pub/media/catalog/product
     */
    private function getMediaBaseDir(): string
    {
        return BP . DIRECTORY_SEPARATOR . 'pub' . DIRECTORY_SEPARATOR
            . 'media' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'product';
    }

    /**
     * Reset queue rows that have been stuck in 'processing' beyond the
     * configured threshold back to 'pending' so the next cron cycle retries them.
     *
     * Rows get stuck when the PHP process is killed mid-run (deploy, OOM, timeout).
     * Only rows whose updated_at is older than STUCK_PROCESSING_THRESHOLD_MINUTES
     * are touched; rows updated more recently may still be actively worked on by
     * a parallel process.
     */
    private function resetStuckProcessingRows(): void
    {
        $conn = $this->resourceConnection->getConnection();
        $queueTable = $this->resourceConnection->getTableName(self::PHOTO_STUDIO_QUEUE_TABLE);
        $cutoff = (new DateTime())
            ->modify(sprintf('-%d minutes', self::STUCK_PROCESSING_THRESHOLD_MINUTES))
            ->format('Y-m-d H:i:s');

        $affected = $conn->update($queueTable,
            ['status' => self::STATUS_PENDING, 'updated_at' => (new DateTime())->format('Y-m-d H:i:s')],
            ['status = ?' => self::STATUS_PROCESSING, 'updated_at <= ?' => $cutoff]
        );

        if ($affected > 0) {
            $this->logInfo(sprintf(
                'DirectGallerySync: Reset %d stuck processing row(s) (stuck > %d min).',
                $affected, self::STUCK_PROCESSING_THRESHOLD_MINUTES
            ), true);
        }
    }

    /**
     * Keep only the newest pending job per product; older pending jobs become 'superseded'.
     *
     * Each payload is the product's full desired gallery. An older job still queued would be
     * undone by the newer one anyway (its new images are deleted again because the newer
     * payload doesn't know their value_ids), so running it only churns files and value_ids.
     * Superseded jobs get no webhook: the webhook is keyed by product and the newer job's
     * webhook reports the final gallery. Their pending downloads are cancelled and temp files
     * that no other queued job needs are removed.
     */
    private function supersedeOlderPendingJobs(): void
    {
        $conn = $this->resourceConnection->getConnection();
        $queueTable = $this->resourceConnection->getTableName(self::PHOTO_STUDIO_QUEUE_TABLE);
        $bucketTable = $this->resourceConnection->getTableName(self::PHOTO_STUDIO_BUCKET_IMAGES_TABLE);

        $older = $conn->fetchPairs(
            $conn->select()
                ->from(['q' => $queueTable], ['q.entity_id', 'newer_id' => 'MAX(n.entity_id)'])
                ->join(
                    ['n' => $queueTable],
                    'n.object_id = q.object_id AND n.type = q.type AND n.status = q.status'
                    . ' AND n.entity_id > q.entity_id',
                    []
                )
                ->where('q.status = ?', self::STATUS_PENDING)
                ->group('q.entity_id')
        );
        if (empty($older)) {
            return;
        }

        $olderIds = array_map('intval', array_keys($older));
        $now = (new DateTime())->format('Y-m-d H:i:s');
        foreach ($older as $queueId => $newerId) {
            $conn->update(
                $queueTable,
                [
                    'status' => self::STATUS_SUPERSEDED,
                    'updated_at' => $now,
                    'response_result' => json_encode(['superseded_by' => (int)$newerId]),
                ],
                ['entity_id = ?' => (int)$queueId, 'status = ?' => self::STATUS_PENDING]
            );
        }

        $conn->update(
            $bucketTable,
            ['status' => self::STATUS_SUPERSEDED, 'updated_at' => $now],
            ['photo_studio_queue_id IN (?)' => $olderIds, 'status = ?' => self::STATUS_PENDING]
        );

        // Remove already-downloaded temp files of superseded jobs unless a live job uses the same file.
        $downloadDir = $this->productHelper->getGoogleBucketImageInitDownloadPath();
        $sanitize = fn($path) => preg_replace('/[^a-zA-Z0-9._-]/', '_', basename((string)$path));
        $liveFiles = array_map($sanitize, $conn->fetchCol(
            $conn->select()
                ->from(['bi' => $bucketTable], ['bucket_image_path'])
                ->join(['q' => $queueTable], 'q.entity_id = bi.photo_studio_queue_id', [])
                ->where('q.status IN (?)', [self::STATUS_PENDING, self::STATUS_PROCESSING])
        ));
        $supersededFiles = array_map($sanitize, $conn->fetchCol(
            $conn->select()
                ->from($bucketTable, ['bucket_image_path'])
                ->where('photo_studio_queue_id IN (?)', $olderIds)
                ->where('status = ?', self::STATUS_DOWNLOADED)
        ));
        foreach (array_diff(array_unique($supersededFiles), $liveFiles) as $file) {
            if (is_file($downloadDir . $file)) {
                unlink($downloadDir . $file);
            }
        }

        $this->logInfo(sprintf(
            'DirectGallerySync: Superseded %d older pending job(s): %s',
            count($older),
            implode(', ', array_map(fn($id, $newer) => "{$id}->{$newer}", array_keys($older), $older))
        ));
    }

    /**
     * Bulk-update the fetched queue rows to 'processing' before any work begins.
     *
     * Locking all rows upfront in a single UPDATE prevents other cron instances
     * from picking up the same jobs concurrently.
     *
     * @param array<int, array{queue: array<string, mixed>, images: array}> $batches
     */
    private function markBatchesAsProcessing(array $batches): void
    {
        $queueIds = array_column(array_column($batches, 'queue'), 'entity_id');
        if (empty($queueIds)) {
            return;
        }

        $conn = $this->resourceConnection->getConnection();
        $queueTable = $this->resourceConnection->getTableName(self::PHOTO_STUDIO_QUEUE_TABLE);

        $conn->update($queueTable,
            ['status' => self::STATUS_PROCESSING, 'updated_at' => (new DateTime())->format('Y-m-d H:i:s')],
            ['entity_id IN (?)' => $queueIds]
        );

        $this->logInfo(sprintf(
            'DirectGallerySync: Marked %d queue job(s) as processing. IDs: %s',
            count($queueIds), implode(', ', $queueIds)
        ));
    }

    /**
     * Persist the final result of a processed batch and hand it to the webhook.
     *
     * Any upload or other error flips the result to success=false /
     * status_code=500; the row still moves to ready_for_webhook so IXPF
     * receives the failure.
     *
     * @param int $queueId
     * @param array $galleryImages Current gallery state.
     * @param array[] $uploadErrors Payload items that could not be added, each with an 'error' key.
     * @param string[] $othersErrors Human-readable error messages.
     * @return bool True when there were no errors.
     */
    private function completeQueue(int $queueId, array $galleryImages, array $uploadErrors, array $othersErrors): bool
    {
        $success = empty($uploadErrors) && empty($othersErrors);

        $this->updateQueueStatus($queueId, self::STATUS_READY_FOR_WEBHOOK, [
            'success' => $success,
            'status' => $success ? self::STATUS_READY_FOR_WEBHOOK : self::STATUS_FAILED,
            'status_code' => $success ? self::RESPONSE_CODE_SUCCESS : self::RESPONSE_CODE_FAILED,
            'details' => [
                'gallery_images' => $galleryImages,
                'upload_errors' => $uploadErrors,
                'others_errors' => $othersErrors,
            ],
        ]);

        return $success;
    }

    /**
     * Convenience wrapper: report a batch that could not be processed at all
     * (bad payload, missing product) as success=false / status_code=500.
     *
     * @param int $queueId
     * @param string[] $othersErrors Human-readable error messages.
     */
    private function failQueue(int $queueId, array $othersErrors): void
    {
        $this->completeQueue($queueId, [], [], $othersErrors);
    }

    /**
     * Persist a new status and optional response_result JSON to a queue row.
     *
     * The response_result shape mirrors PhotoStudio::manageGalleryImages():
     * {
     *   "success": true|false,
     *   "status":  "ready_for_webhook"|"failed",
     *   "status_code": 200|500,
     *   "details": {
     *     "gallery_images": [...],
     *     "upload_errors":  [...],
     *     "others_errors":  [...]
     *   }
     * }
     *
     * @param int $queueId
     * @param string $status One of the STATUS_* constants.
     * @param array<string, mixed> $result Result payload; omitted if empty.
     */
    private function updateQueueStatus(int $queueId, string $status, array $result = []): void
    {
        $conn = $this->resourceConnection->getConnection();
        $queueTable = $this->resourceConnection->getTableName(self::PHOTO_STUDIO_QUEUE_TABLE);

        $data = ['status' => $status, 'updated_at' => (new DateTime())->format('Y-m-d H:i:s')];
        if (!empty($result)) {
            $data['response_result'] = json_encode($result);
        }

        $conn->update($queueTable, $data, ['entity_id = ?' => $queueId]);
        $this->logDebug(sprintf('DirectGallerySync: Queue ID=%d → %s', $queueId, $status));
    }

    /**
     * Log at INFO level — always written to console; written to file when $fileLog is true.
     *
     * @param string $msg
     * @param bool $fileLog Set false for high-frequency progress lines that should
     *                        stay off the daily log file.
     */
    private function logInfo(string $msg, bool $fileLog = true): void
    {
        if ($fileLog) {
            $this->logger->writeDaily($msg, 'info');
        }
        $this->writeConsole($msg, 'info');
    }

    /**
     * Log at WARNING level — non-fatal issues such as a missing or corrupt file.
     *
     * @param string $msg
     * @param bool $fileLog
     */
    private function logWarning(string $msg, bool $fileLog = true): void
    {
        if ($fileLog) {
            $this->logger->writeDaily($msg, 'warning');
        }
        $this->writeConsole("<comment>$msg</comment>", 'comment');
    }

    /**
     * Log at ERROR level — failures that affect a queue row's outcome.
     *
     * @param string $msg
     * @param bool $fileLog
     */
    private function logError(string $msg, bool $fileLog = true): void
    {
        if ($fileLog) {
            $this->logger->writeDaily($msg, 'error');
        }
        $this->writeConsole("<error>$msg</error>", 'error');
    }

    /**
     * Log at DEBUG level — written to file and, when console verbosity is
     * VERBOSE or higher (-v flag), to the console output in gray.
     *
     * @param string $msg
     * @param bool $fileLog
     */
    private function logDebug(string $msg, bool $fileLog = true): void
    {
        if ($fileLog) {
            $this->logger->writeDaily($msg, 'debug');
        }
        if ($this->consoleOutput?->isVerbose()) {
            $this->consoleOutput->writeln("<fg=gray>$msg</>");
        }
    }

    /**
     * Write a pre-formatted message to the Symfony console output.
     *
     * Error and warning messages are already wrapped in their XML tags by
     * the caller; plain info messages are wrapped here.
     *
     * @param string $msg
     * @param string $tag 'info' | 'error' | 'comment'
     */
    private function writeConsole(string $msg, string $tag = 'info'): void
    {
        if ($this->consoleOutput === null) {
            return;
        }
        $formatted = in_array($tag, ['error', 'comment'], true) ? $msg : "<$tag>$msg</$tag>";
        $this->consoleOutput->writeln($formatted);
    }
}
