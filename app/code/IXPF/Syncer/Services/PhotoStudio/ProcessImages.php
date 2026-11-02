<?php

declare(strict_types=1);

namespace IXPF\Syncer\Services\PhotoStudio;

use DateTime;
use Exception;
use IXPF\Syncer\Helper\Helpers as ProductHelper;
use IXPF\Syncer\Helper\Logger\PhotoStudioLogger;
use IXPF\Syncer\Helper\PhotoStudio;
use Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Gallery\Processor as GalleryProcessor;
use Magento\Catalog\Model\ProductRepository;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessImages
{
    // =========================================================================
    // CONSTANTS
    // =========================================================================

    const PHOTO_STUDIO_QUEUE_TABLE = 'ixpf_photo_studio_queue';
    const PHOTO_STUDIO_BUCKET_IMAGES_TABLE = 'ixpf_photo_studio_bucket_images';

    /**
     * Queue lifecycle statuses
     *
     * Flow:
     *   pending
     *     → processing        (locked for processing)
     *     → failed            (unrecoverable: bad payload / product not found)
     *     → magento_failed    (transient Magento error: retry cron will re-attempt)
     *     → ready_for_webhook (product saved OK: webhook cron dispatches next)
     *     → completed         (webhook cron confirmed delivery)
     *     → webhook_failed    (webhook cron could not deliver)
     */
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_DOWNLOADED = 'downloaded';
    const STATUS_FAILED = 'failed';
    const STATUS_MAGENTO_FAILED = 'failed_by_magento';
    const STATUS_COMPLETED = 'completed';
    const STATUS_READY_FOR_WEBHOOK = 'ready_for_webhook';

    /** Roles automatically assigned to the first image if none are specified. */
    const DEFAULT_IMAGE_ROLES = ['image', 'small_image', 'thumbnail'];

    /** status_code reported to IXPF in response_result. */
    const RESPONSE_CODE_SUCCESS = 200;
    const RESPONSE_CODE_FAILED = 500;
    /**
     * Reset queue rows that are permanently stuck in 'processing'.
     *
     * A row gets stuck when the PHP process is killed mid-run (deploy, OOM,
     * timeout, etc.).  Because no other worker will ever finish that row it
     * must be reset to 'pending' so the next cron cycle picks it up again.
     *
     * We call this once at the very start of process() — before fetching new
     * batches — so recovery happens automatically on every run.
     */
    /**
     * How long (in minutes) a row must have been in 'processing' before it is
     * considered stuck and eligible for reset.  Rows updated more recently than
     * this threshold are still being actively worked on by another process and
     * must NOT be touched.
     */
    const STUCK_PROCESSING_THRESHOLD_MINUTES = 15;

    /** @var OutputInterface|null */
    private ?OutputInterface $consoleOutput = null;

    public function __construct(
        protected ResourceConnection                              $resourceConnection,
        protected PhotoStudio                                     $photoStudioHelper,
        protected ProductHelper                                   $productHelper,
        protected readonly PhotoStudioLogger                      $logger,
        protected ProductRepository                               $productRepository,
        protected GalleryProcessor                                $galleryProcessor,
        protected ProductAttributeMediaGalleryManagementInterface $productAttributeMediaGalleryManagement,
    )
    {
    }

    /**
     * Main entry point — called by the cron job.
     *
     * @param int $limit Maximum number of queue batches to process.
     * @param OutputInterface|null $consoleOutput Symfony console output (CLI runs only).
     */
    public function process(int $limit = 15, ?OutputInterface $consoleOutput = null): bool
    {
        $this->consoleOutput = $consoleOutput;

        $this->logInfo(sprintf('ProcessImages: Starting — limit=%d', $limit), false);

        // Reset any rows that are stuck in 'processing' from a previous
        // crashed run or a mid-deploy kill signal back to 'pending' so they
        // are picked up again in this (or the next) cron cycle.
        $this->resetStuckProcessingRows();

        $batches = $this->getNextBatches($limit);

        if (empty($batches)) {
            $this->logInfo('ProcessImages: No pending batches found, exiting.', false);
            return true;
        }

        $this->logInfo(sprintf('ProcessImages: Found %d batch(es) to process.', count($batches)), false);
        $this->markBatchesAsProcessing($batches);

        $allSucceeded = true;
        foreach ($batches as $batch) {
            $queueId = (int)$batch['queue']['entity_id'];
            $productId = (int)$batch['queue']['object_id'];

            $this->logInfo(sprintf(
                'ProcessImages: Processing queue ID=%d | product ID=%d',
                $queueId,
                $productId
            ), false);

            if (!$this->processBatch($batch)) {
                $allSucceeded = false;
            }

            $this->logInfo("-----------------------------------------------------", false);
        }

        $this->logInfo('ProcessImages: Run complete.', false);

        return $allSucceeded;
    }


    /**
     * Fetch the next $limit pending queue jobs where ALL related bucket images
     * reached a final download state — 'downloaded' or 'failed' — using a single
     * JOIN + HAVING query. Failed downloads are reported back to IXPF as upload
     * errors instead of leaving the queue job pending forever.
     *
     * @return array<int, array{queue: array, images: array}>
     */
    public function getNextBatches(int $limit = 15): array
    {
        $connection = $this->resourceConnection->getConnection();
        $queueTable = $this->resourceConnection->getTableName(self::PHOTO_STUDIO_QUEUE_TABLE);
        $bucketTable = $this->resourceConnection->getTableName(self::PHOTO_STUDIO_BUCKET_IMAGES_TABLE);

        $queueSelect = $connection->select()
            ->from(['q' => $queueTable], ['q.*'])
            ->joinLeft(['bi' => $bucketTable], 'bi.photo_studio_queue_id = q.entity_id', [])
            ->where('q.status = ?', self::STATUS_PENDING)
            ->group('q.entity_id')
            ->having($connection->quoteInto(
                'COUNT(bi.entity_id) = 0 OR COUNT(bi.entity_id) = SUM(bi.status IN (?))',
                [self::STATUS_DOWNLOADED, self::STATUS_FAILED]
            ))
            ->order('q.created_at ASC')
            ->limit($limit);

        $queueRows = $connection->fetchAll($queueSelect);

        if (empty($queueRows)) {
            return [];
        }

        $queueIds = array_column($queueRows, 'entity_id');

        $allImages = $connection->fetchAll(
            $connection->select()
                ->from(['bi' => $bucketTable])
                ->where('bi.photo_studio_queue_id IN (?)', $queueIds)
        );

        $imagesByQueueId = [];
        foreach ($allImages as $image) {
            $imagesByQueueId[$image['photo_studio_queue_id']][] = $image;
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
     * response_result shape stored to DB (mirrors PhotoStudio::manageGalleryImages):
     * {
     *   "success": true|false,
     *   "status":  "ready_for_webhook"|"failed"|"magento_failed",
     *   "status_code": 200|500,
     *   "details": {
     *     "gallery_images": [...],
     *     "upload_errors":  [...],
     *     "others_errors":  [...]
     *   }
     * }
     *
     * Any error (failed download, missing/corrupt file) gives success=false /
     * status_code=500; the job still goes to ready_for_webhook so IXPF is notified.
     *
     * @return bool True when the batch finished without any error.
     */
    private function processBatch(array $batch): bool
    {
        $queue = $batch['queue'];
        $queueId = (int)$queue['entity_id'];
        $productId = (int)$queue['object_id'];

        // ── Step 1: Decode & validate payload
        $payload = json_decode($queue['payload'], true);

        // null / non-array means the JSON itself is malformed → hard fail
        if (!is_array($payload)) {
            $this->logError(sprintf(
                'ProcessImages: Queue ID=%d — Invalid payload JSON (could not decode).',
                $queueId
            ));
            $this->failQueue($queueId, ['Invalid payload JSON (could not decode).']);
            return false;
        }

        // Empty array [] means "remove all images from this product"
        if (empty($payload)) {
            $this->logInfo(sprintf(
                'ProcessImages: Queue ID=%d — Payload is empty, removing all images from product ID=%d.',
                $queueId,
                $productId
            ));

            $product = $this->loadProduct($productId, $queueId);
            if ($product === null) {
                return false;
            }

            [$product, $deleteErrors] = $this->deleteRemovedImages($product, $productId, $queueId, []);
            if ($product === null) {
                return false;
            }

            if (!$this->saveProduct($product, $queueId, [], $deleteErrors)) {
                return false;
            }

            return $this->completeQueue($queueId, [], [], $deleteErrors);
        }

        // ── Step 2: Classify payload items
        [$newImages, $existingImages, $keepIds] = $this->classifyPayloadItems($payload);

        $this->logDebug(sprintf(
            'ProcessImages: Queue ID=%d | new=%d existing=%d keepIds=%s',
            $queueId,
            count($newImages),
            count($existingImages),
            implode(',', $keepIds) ?: 'none'
        ));

        // ── Step 3: Load product
        $product = $this->loadProduct($productId, $queueId);
        if ($product === null) {
            return false;
        }

        $uploadErrors = [];
        $otherErrors = [];

        // ── Step 4: Delete images no longer in payload
        [$product, $deleteErrors] = $this->deleteRemovedImages($product, $productId, $queueId, $keepIds);
        if ($product === null) {
            return false;
        }
        $otherErrors = array_merge($otherErrors, $deleteErrors);

        // ── Step 5: Add new images from disk
        [$addErrors, $addUploadErrors] = $this->addNewImages(
            $product,
            $newImages,
            $this->getFailedDownloads($batch['images'])
        );
        $otherErrors = array_merge($otherErrors, $addErrors);
        $uploadErrors = array_merge($uploadErrors, $addUploadErrors);

        // ── Step 6: Update existing images
        $updateErrors = $this->updateExistingImages($product, $existingImages);
        $otherErrors = array_merge($otherErrors, $updateErrors);


        // ── Step 7: Save product
        if (!$this->saveProduct($product, $queueId, $uploadErrors, $otherErrors)) {
            return false;
        }

        // ── Step 8: Set final status & persist response_result
        $this->logInfo(sprintf(
            'ProcessImages: Queue ID=%d | Product ID=%d — Done. upload_errors=%d others_errors=%d',
            $queueId,
            $productId,
            count($uploadErrors),
            count($otherErrors)
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
     * @return array{0: array, 1: array, 2: int[]}
     */
    private function classifyPayloadItems(array $payload): array
    {
        $newImages = [];
        $existingImages = [];
        $keepIds = [];

        foreach ($payload as $item) {
            $id = $item['id'] ?? '';

            if ($id === 'new_image') {
                $newImages[] = $item;
            } elseif ($id !== '' && $id !== 'tmp') {
                $existingImages[] = $item;
                $keepIds[] = (int)$id;
            }
        }

        return [$newImages, $existingImages, $keepIds];
    }


    /**
     * Load a product by ID. Returns null and sets STATUS_FAILED on failure.
     */
    private function loadProduct(int $productId, int $queueId): ?Product
    {
        try {
            return $this->productRepository->getById($productId, true, 0, true);
        } catch (Exception $e) {
            $this->logError(sprintf(
                'ProcessImages: Queue ID=%d — Product ID=%d not found. Error: %s',
                $queueId,
                $productId,
                $e->getMessage()
            ));
            $this->failQueue($queueId, ['Product not found: ' . $e->getMessage()]);
            return null;
        }
    }

    /**
     * Save the product. Returns false and sets STATUS_MAGENTO_FAILED on failure.
     */
    private function saveProduct(
        Product $product,
        int     $queueId,
        array   $uploadErrors = [],
        array   $otherErrors = []
    ): bool
    {
        try {
            $product->save();
            //$this->productRepository->save($product);
            $this->logInfo(sprintf(
                'ProcessImages: Queue ID=%d — Product SKU=%s saved successfully.',
                $queueId,
                $product->getSku()
            ));
            return true;
        } catch (Exception $e) {
            $this->logError(sprintf(
                'ProcessImages: Queue ID=%d — Product SKU=%s save failed. Error: %s',
                $queueId,
                $product->getSku(),
                $e->getMessage()
            ));
            $this->updateQueueStatus($queueId, self::STATUS_MAGENTO_FAILED, [
                'success' => false,
                'status' => self::STATUS_MAGENTO_FAILED,
                'status_code' => self::RESPONSE_CODE_FAILED,
                'details' => [
                    'gallery_images' => [],
                    'upload_errors' => $uploadErrors,
                    'others_errors' => array_merge($otherErrors, ['Product save failed: ' . $e->getMessage()]),
                ],
            ]);
            return false;
        }
    }


    /**
     * @param int[] $keepIds
     * @return array{0: ?Product, 1: string[]}
     */
    private function deleteRemovedImages(
        Product $product,
        int     $productId,
        int     $queueId,
        array   $keepIds
    ): array
    {
        $currentIds = $this->getCurrentGalleryIds($product);
        $idsToRemove = array_diff($currentIds, $keepIds);

        if (empty($idsToRemove)) {
            $this->logDebug(sprintf(
                'ProcessImages: Queue ID=%d — No images to remove.',
                $queueId
            ));
            return [$product, []];
        }

        $this->logInfo(sprintf(
            'ProcessImages: Queue ID=%d — Removing image IDs: %s',
            $queueId,
            implode(', ', $idsToRemove)
        ));

        $errors = $this->removeImagesFromProduct($product->getSku(), array_values($idsToRemove), $productId);

        if (!empty($errors)) {
            $this->logWarning(sprintf(
                'ProcessImages: Queue ID=%d — %d image removal error(s): %s',
                $queueId,
                count($errors),
                implode(' | ', $errors)
            ));
        }

        try {
            $product = $this->productRepository->getById($productId, true, 0, true);
        } catch (Exception $e) {
            $this->logError(sprintf(
                'ProcessImages: Queue ID=%d — Product reload after delete failed. Error: %s',
                $queueId,
                $e->getMessage()
            ));
            $this->updateQueueStatus($queueId, self::STATUS_MAGENTO_FAILED, [
                'success' => false,
                'status' => self::STATUS_MAGENTO_FAILED,
                'status_code' => self::RESPONSE_CODE_FAILED,
                'details' => [
                    'gallery_images' => [],
                    'upload_errors' => [],
                    'others_errors' => array_merge($errors, ['Product reload after delete failed: ' . $e->getMessage()]),
                ],
            ]);
            return [null, $errors];
        }

        return [$product, $errors];
    }

    /**
     * @return int[]
     */
    private function getCurrentGalleryIds(Product $product): array
    {
        $gallery = $product->getMediaGallery('images');

        if (!is_array($gallery) || empty($gallery)) {
            return [];
        }

        return array_map('intval', array_keys($gallery));
    }

    /**
     * @param int[] $imageIds
     * @return string[]
     */
    private function removeImagesFromProduct(string $sku, array $imageIds, int $productId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $errors = [];

        foreach ($imageIds as $imageId) {
            try {
                $mediaValue = $connection->fetchOne(
                    'SELECT value FROM catalog_product_entity_media_gallery WHERE value_id = ?',
                    [$imageId]
                );

                if ($mediaValue) {
                    foreach ($this->photoStudioHelper->getImageRoleAttributeIds() as $attrId) {
                        $connection->delete(
                            'catalog_product_entity_varchar',
                            [
                                'attribute_id = ?' => $attrId,
                                'entity_id = ?' => $productId,
                                'value = ?' => $mediaValue,
                            ]
                        );
                    }
                }

                $this->productAttributeMediaGalleryManagement->remove($sku, $imageId);
                $this->logDebug(sprintf(
                    'ProcessImages: Removed image ID=%d from SKU=%s',
                    $imageId,
                    $sku
                ));

            } catch (Exception $e) {
                $msg = sprintf(
                    'ProcessImages: Remove image error — SKU=%s | image_id=%d | error=%s',
                    $sku,
                    $imageId,
                    $e->getMessage()
                );
                $this->logError($msg);
                $errors[] = 'Could not remove image ID ' . $imageId . ': ' . $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * Add new images to the product gallery from pre-downloaded files on disk.
     *
     * Returns [ $otherErrors[], $uploadErrors[] ] matching the
     * manageGalleryImages() response shape where:
     *   $uploadErrors  — per-image failures with original item data attached
     *   $otherErrors   — general non-fatal errors (missing file, etc.)
     *
     * @return array{0: string[], 1: array[]}
     */
    private function addNewImages(Product &$product, array $newImages, array $failedDownloads = []): array
    {
        if (empty($newImages)) {
            return [[], []];
        }

        $downloadBaseDir = $this->productHelper->getGoogleBucketImageInitDownloadPath();
        $takenRoles      = $this->getAlreadyAssignedRoles($product);
        $otherErrors     = [];
        $uploadErrors    = [];

        foreach ($newImages as $item) {
            $bucketUrl  = (string)($item['bucket_url'] ?? '');
            // Same sanitising as ProcessGoogleBucketImages::sanitizeFilename() so the paths match.
            $filename   = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($bucketUrl));
            $sourcePath = $downloadBaseDir . $filename;

            if (isset($failedDownloads[$bucketUrl])) {
                $msg = sprintf('Download failed, skipped: %s (%s)', $filename, $failedDownloads[$bucketUrl]);
                $this->logWarning('ProcessImages: ' . $msg);
                $uploadErrors[] = array_merge($item, ['error' => $msg]);
                $otherErrors[] = $msg;
                continue;
            }

            if (!file_exists($sourcePath) || filesize($sourcePath) === 0) {
                $msg = 'File not found on disk, skipped: ' . $filename;
                $this->logWarning('ProcessImages: ' . $msg);
                $uploadErrors[] = array_merge($item, ['error' => $msg]);
                $otherErrors[] = $msg;
                continue;
            }

            // Validate image
            if (function_exists('imagecreatefromstring')) {
                $testImage = @imagecreatefromstring(file_get_contents($sourcePath));
                if ($testImage === false) {
                    $msg = 'Image corrupted, skipped: ' . $filename;
                    $this->logWarning('ProcessImages: ' . $msg);
                    $uploadErrors[] = array_merge($item, ['error' => $msg]);
                    $otherErrors[] = $msg;
                    continue;
                }
                unset($testImage);
            }

            $roles = $this->resolveRoles($item['role'] ?? [], $takenRoles);
            foreach ($roles as $role) {
                $takenRoles[] = $role;
            }

            try {

                $product->addImageToMediaGallery(
                    $sourcePath,
                    $roles,
                    false,
                    false
                );

                $gallery = $product->getMediaGalleryEntries();
                if (!empty($gallery)) {
                    $lastEntry = $gallery[array_key_last($gallery)];

                    if ($lastEntry) {
                        $lastEntry->setLabel($item['label'] ?? '');
                        $lastEntry->setPosition((int)($item['position'] ?? 0));
                        $lastEntry->setDisabled((bool)($item['disabled'] ?? 0));

                        if (!empty($roles)) {
                            $lastEntry->setTypes($roles);
                        }

                        $product->setMediaGalleryEntries($gallery);
                    }
                }

                $this->logDebug(sprintf(
                    'ProcessImages: Added image file=%s | roles=%s',
                    $filename,
                    implode(',', $roles)
                ));

                //-- Clean up downloaded file after successful processing
                if (file_exists($sourcePath)) {
                    @unlink($sourcePath);
                }

            } catch (Exception $e) {
                $msg = sprintf('Add image error — file=%s | error=%s', $filename, $e->getMessage());
                $this->logError($msg);
                $uploadErrors[] = array_merge($item, ['error' => $e->getMessage()]);
                $otherErrors[]  = 'Failed to add image ' . $filename . ': ' . $e->getMessage();
            }
        }

        return [$otherErrors, $uploadErrors];
    }


    /**
     * @return string[]
     * @throws LocalizedException
     */
    private function updateExistingImages(Product &$product, array $existingImages): array
    {
        if (empty($existingImages)) {
            return [];
        }

        $gallery = $product->getMediaGalleryEntries();
        $errors  = [];

        if (empty($gallery)) {
            $this->logDebug('ProcessImages: updateExistingImages — gallery is empty, nothing to update.');
            return $errors;
        }

        $dataById = [];
        foreach ($existingImages as $img) {
            if (isset($img['id'])) {
                $dataById[(int)$img['id']] = $img;
            }
        }

        foreach ($gallery as $entry) {
            $entryId = (int)$entry->getId();

            if (!isset($dataById[$entryId])) {
                continue;
            }

            try {
                $img = $dataById[$entryId];

                $entry->setLabel($img['label'] ?? '');
                $entry->setPosition((int)($img['position'] ?? 0));
                $entry->setDisabled((bool)($img['disabled'] ?? false));

                $roles = $img['role'] ?? [];
                if (!empty($roles)) {
                    if (in_array('image', $roles)) {
                        $product->setImage($entry->getFile());
                    }
                    if (in_array('small_image', $roles)) {
                        $product->setSmallImage($entry->getFile());
                    }
                    if (in_array('thumbnail', $roles)) {
                        $product->setThumbnail($entry->getFile());
                    }
                    if (in_array('swatch_image', $roles)) {
                        $product->setData('swatch_image', $entry->getFile());
                    }
                }

                $this->logDebug(sprintf(
                    'ProcessImages: Updated image ID=%d | label="%s" | position=%d | roles=%s',
                    $entryId,
                    $img['label'] ?? '',
                    (int)($img['position'] ?? 0),
                    implode(',', $roles) ?: 'none'
                ));

            } catch (Exception $e) {
                $msg = sprintf(
                    'ProcessImages: Update existing image error — ID=%d | error=%s',
                    $entryId,
                    $e->getMessage()
                );
                $this->logError($msg);
                $errors[] = 'Could not update image ID ' . $entryId . ': ' . $e->getMessage();
            }
        }

        $product->setMediaGalleryEntries($gallery);

        return $errors;
    }


    /**
     * @return string[]
     */
    private function getAlreadyAssignedRoles(Product $product): array
    {
        $taken = [];

        foreach (self::DEFAULT_IMAGE_ROLES as $role) {
            $value = $product->getData($role);
            if (!empty($value) && $value !== 'no_selection') {
                $taken[] = $role;
            }
        }

        return $taken;
    }

    /**
     * @param string[] $payloadRoles
     * @param string[] $takenRoles
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
     * Sync product-level role attributes so Magento's EAV layer persists
     * the correct file path against each role on save.
     */
    private function syncProductRoleAttributes(Product $product, string $file, array $roles): void
    {
        $roleSetters = [
            'image' => fn() => $product->setImage($file),
            'small_image' => fn() => $product->setSmallImage($file),
            'thumbnail' => fn() => $product->setThumbnail($file),
            'swatch_image' => fn() => $product->setData('swatch_image', $file),
        ];

        foreach ($roles as $role) {
            if (isset($roleSetters[$role])) {
                ($roleSetters[$role])();
            }
        }
    }


    /**
     * Set label and position on the last gallery entry after addImage().
     */
    private function setLastEntryAttributes(Product $product, array $item): void
    {
        $mediaGallery = $product->getMediaGalleryEntries();

        if (empty($mediaGallery)) {
            return;
        }

        $lastEntry = $mediaGallery[array_key_last($mediaGallery)];
        $lastEntry->setLabel($item['label'] ?? '');
        $lastEntry->setPosition((int)($item['position'] ?? 0));

        $product->setMediaGalleryEntries($mediaGallery);
    }

    /**
     * Persist the final result of a processed batch and hand it to the webhook.
     *
     * Any upload or other error flips the result to success=false /
     * status_code=500; the row still moves to ready_for_webhook so IXPF
     * receives the failure.
     *
     * @param array[] $uploadErrors Payload items that could not be added, each with an 'error' key.
     * @param string[] $othersErrors
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
     * @param string[] $othersErrors
     */
    private function failQueue(int $queueId, array $othersErrors): void
    {
        $this->completeQueue($queueId, [], [], $othersErrors);
    }

    private function resetStuckProcessingRows(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $queueTable = $this->resourceConnection->getTableName(self::PHOTO_STUDIO_QUEUE_TABLE);

        // Only reset rows that have been stuck in 'processing' for longer than
        // the threshold.  Rows updated within the last 15 minutes are still
        // actively being processed by another running instance — leave them alone.
        $cutoff = (new DateTime())
            ->modify(sprintf('-%d minutes', self::STUCK_PROCESSING_THRESHOLD_MINUTES))
            ->format('Y-m-d H:i:s');

        $affected = $connection->update(
            $queueTable,
            [
                'status'     => self::STATUS_PENDING,
                'updated_at' => (new DateTime())->format('Y-m-d H:i:s'),
            ],
            [
                'status = ?'     => self::STATUS_PROCESSING,
                'updated_at <= ?' => $cutoff,           // stuck longer than threshold
            ]
        );

        if ($affected > 0) {
            $this->logInfo(sprintf(
                'ProcessImages: Reset %d stuck processing row(s) → pending (stuck > %d min).',
                $affected,
                self::STUCK_PROCESSING_THRESHOLD_MINUTES
            ), true);
        }
    }

    /**
     * Bulk-mark fetched queue jobs as 'processing' before any work begins.
     */
    private function markBatchesAsProcessing(array $batches): void
    {
        $queueIds = array_column(array_column($batches, 'queue'), 'entity_id');

        if (empty($queueIds)) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $queueTable = $this->resourceConnection->getTableName(self::PHOTO_STUDIO_QUEUE_TABLE);

        $connection->update(
            $queueTable,
            [
                'status' => self::STATUS_PROCESSING,
                'updated_at' => (new DateTime())->format('Y-m-d H:i:s'),
            ],
            ['entity_id IN (?)' => $queueIds]
        );

        $this->logInfo(sprintf(
            'ProcessImages: Marked %d queue job(s) as processing. IDs: %s',
            count($queueIds),
            implode(', ', $queueIds)
        ));
    }

    /**
     * Update a queue row's status and persist the result as JSON in
     * response_result, matching the shape from PhotoStudio::manageGalleryImages():
     *
     * {
     *   "success": true|false,
     *   "status":  "ready_for_webhook"|"failed"|"magento_failed",
     *   "details": {
     *     "gallery_images": [...],
     *     "upload_errors":  [...],
     *     "others_errors":  [...]
     *   }
     * }
     */
    private function updateQueueStatus(int $queueId, string $status, array $result = []): void
    {
        $connection = $this->resourceConnection->getConnection();
        $queueTable = $this->resourceConnection->getTableName(self::PHOTO_STUDIO_QUEUE_TABLE);

        $data = [
            'status' => $status,
            'updated_at' => (new DateTime())->format('Y-m-d H:i:s'),
        ];

        if (!empty($result)) {
            $data['response_result'] = json_encode($result);
        }

        $connection->update($queueTable, $data, ['entity_id = ?' => $queueId]);

        $this->logDebug(sprintf(
            'ProcessImages: Queue ID=%d status updated → %s',
            $queueId,
            $status
        ));
    }


    /**
     * Log at INFO level — always written to file + console (if attached).
     */
    private function logInfo(string $message, $fileLog = true): void
    {
        if ($fileLog) {
            $this->logger->writeDaily($message, 'info');
        }
        $this->writeConsole($message, 'info');
    }

    /**
     * Log at WARNING level.
     */
    private function logWarning(string $message, $fileLog = true): void
    {
        if ($fileLog) {
            $this->logger->writeDaily($message, 'warning');
        }
        $this->writeConsole("<comment>$message</comment>", 'comment');
    }

    /**
     * Log at ERROR level.
     */
    private function logError(string $message, $fileLog = true): void
    {
        if ($fileLog) {
            $this->logger->writeDaily($message, 'error');
        }
        $this->writeConsole("<error>$message</error>", 'error');
    }

    /**
     * Log at DEBUG level — written to file only; suppressed on console unless
     * the output verbosity is VERBOSE or higher.
     */
    private function logDebug(string $message, $fileLog = true): void
    {
        if ($fileLog) {
            $this->logger->writeDaily($message, 'debug');
        }

        if (
            $this->consoleOutput !== null
            && $this->consoleOutput->isVerbose()
        ) {
            $this->consoleOutput->writeln("<fg=gray>$message</>");
        }
    }

    /**
     * Write a pre-formatted message to the Symfony console output.
     * The $tag param is used when the message has no wrapping XML tags yet
     * (plain info messages); errors/warnings already embed their own tags.
     */
    private function writeConsole(string $message, string $tag = 'info'): void
    {
        if ($this->consoleOutput === null) {
            return;
        }

        // info messages get wrapped; error/warning messages are pre-tagged.
        $formatted = in_array($tag, ['error', 'comment'], true)
            ? $message
            : "<$tag>$message</$tag>";

        $this->consoleOutput->writeln($formatted);
    }
}
