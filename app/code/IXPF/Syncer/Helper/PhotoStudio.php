<?php

declare(strict_types=1);

namespace IXPF\Syncer\Helper;

use Exception;
use Google\Cloud\Storage\StorageClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use IXPF\Syncer\Helper\Logger\PhotoStudioLogger;
use IXPF\Syncer\Model\ResourceModel\IxpfPhotoStudioQueue;
use IXPF\Syncer\Services\Notification\SlackNotifier;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductRepository;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface;
use IXPF\Syncer\Helper\Helpers as ProductHelper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Catalog\Model\Product\Gallery\Processor;
use RuntimeException;
use Zend_Db_Expr;


class PhotoStudio extends AbstractHelper
{
    public function __construct(
        Context                              $context,
        protected readonly Config $configHelper,
        protected readonly PhotoStudioLogger $logger,
        protected ProductRepository $productRepository,
        protected ResourceConnection         $resourceConnection,
        protected ProductHelper              $productHelper,
        protected Processor $galleryProcessor,
        protected SlackNotifier $slackNotifier,
        protected ProductAttributeMediaGalleryManagementInterface $productAttributeMediaGalleryManagement
    )
    {
        parent::__construct($context);
    }

    /** Image role attribute codes handled by the photo-studio gallery sync. */
    private const IMAGE_ROLE_CODES = ['image', 'small_image', 'thumbnail', 'swatch_image'];

    /** @var int[]|null Resolved image-role attribute_ids (lazy, per request). */
    private ?array $imageRoleAttributeIds = null;

    /**
     * Resolve the image-role EAV attribute_ids for this instance's
     * catalog_product entity type. NEVER hardcode these — the IDs differ
     * between databases and a stale value corrupts (delete) or breaks (insert,
     * FK 1452) the varchar table.
     *
     * @return int[]
     */
    public function getImageRoleAttributeIds(): array
    {
        if ($this->imageRoleAttributeIds !== null) {
            return $this->imageRoleAttributeIds;
        }

        $conn = $this->resourceConnection->getConnection();
        $eavAttrT = $this->resourceConnection->getTableName('eav_attribute');
        $eavTypeT = $this->resourceConnection->getTableName('eav_entity_type');

        $ids = $conn->fetchCol(
            "SELECT a.attribute_id
               FROM {$eavAttrT} a
               JOIN {$eavTypeT} e ON e.entity_type_id = a.entity_type_id
              WHERE e.entity_type_code = 'catalog_product'
                AND a.attribute_code IN (" . $conn->quote(self::IMAGE_ROLE_CODES) . ")"
        );

        $this->imageRoleAttributeIds = array_map('intval', $ids);

        return $this->imageRoleAttributeIds;
    }

    /**
     * @param string $status
     * @return void
     */
    public function runQueue(string $status = IxpfPhotoStudioQueue::STATUS_PENDING): void
    {
        try {
            $queues = [];
            if ($status == IxpfPhotoStudioQueue::STATUS_PENDING) {
                $queues = $this->getPendingQueueJob(5, true) ?? [];
            }
            if ($status == IxpfPhotoStudioQueue::STATUS_MAGENTO_FAILED) {
                $queues = $this->getFailedByMagentoQueueJob(5, true) ?? [];
            }

            foreach ($queues as $queue) {
                try {

                    $payload = json_decode($queue['payload'], true);
                    if (!is_array($payload)) {
                        $this->updateStatus(intval($queue['entity_id']), IxpfPhotoStudioQueue::STATUS_FAILED);
                        $this->logger->writeDaily('Invalid payload for queue ID ' . $queue['entity_id'].' Product ID: '.($queue['object_id'] ?? 'N/A'));
                        continue;
                    }

                    $result = $this->manageGalleryImages($queue['object_id'], $payload);
                    if ($result['success']) {
                        $this->updateStatus(intval($queue['entity_id']), IxpfPhotoStudioQueue::STATUS_READY_FOR_WEBHOOK);
                        $this->updateResponseResult(intval($queue['entity_id']), $result);
                        $this->logger->writeDaily('Successfully processed queue ID ' . $queue['entity_id']);
                    } else {
                        $this->updateStatus(intval($queue['entity_id']), IxpfPhotoStudioQueue::STATUS_MAGENTO_FAILED);
                        $this->updateResponseResult(intval($queue['entity_id']), $result);
                        $this->logger->writeDaily('Failed to process queue ID ' . $queue['entity_id']);
                        $this->logger->writeDaily('Details: ' . json_encode($result));

                        //-- Alert via Slack with error details
                        $this->slackNotifier->warning("PhotoStudio Queue Job Failed", [
                            [
                                'title' => 'Error Details',
                                'value' => json_encode([
                                    'queue_id'   => $queue['entity_id'] ?? null,
                                    'product_id' => $queue['object_id'] ?? null,
                                    'result'     => $result,
                                ], JSON_PRETTY_PRINT),
                                'short' => false,
                            ],
                        ]);
                    }
                } catch (Exception $e) {
                    $this->updateStatus(intval($queue['entity_id']), IxpfPhotoStudioQueue::STATUS_MAGENTO_FAILED);
                    $this->logger->writeDaily('Error processing queue ID ' . $queue['entity_id'] . ': ' . $e->getMessage());

                    //-- Alert via Slack with error details
                    $this->slackNotifier->warning("PhotoStudio Queue Job Failed", [
                        [
                            'title' => 'Error Details',
                            'value' => json_encode([
                                'queue_id'   => $queue['entity_id'] ?? null,
                                'product_id' => $queue['object_id'] ?? null,
                                'error'      => $e->getMessage(),
                            ], JSON_PRETTY_PRINT),
                            'short' => false,
                        ],
                    ]);
                }
            }
        } catch (Exception $e) {
            $this->logger->writeDaily('Error in ProductGallery cron: ' . $e->getMessage());
        }
    }

    /**
     * @param string $status
     * @return bool
     * @throws GuzzleException
     */
    public function webhookDispatch(string $status = IxpfPhotoStudioQueue::STATUS_READY_FOR_WEBHOOK): bool
    {
        //-- dispatch the ready_for_webhook jobs to webhook
        $webhookEnabled = $this->configHelper->isProductWebhookEnabled();
        $shopObjectId = $this->configHelper->getShopObjectId();
        $webHookUrl = $this->configHelper->getProductWebhookUrl();
        $webHookUrlSecret = $this->configHelper->getProductWebhookSecret();

        if (!$webhookEnabled || !$webHookUrl || !$webHookUrlSecret || !$shopObjectId) {
            $this->logger->writeDaily('PhotoStudio Webhook Dispatch: Webhook configuration is incomplete or disabled. Skipping dispatch.');
            return false;
        }

        $connection = $this->resourceConnection->getConnection();
        try {
            $jobs = $this->getWebhookQueueJob(10, $status);
            foreach ($jobs as $job) {
                try {
                    $connection->beginTransaction();
                    $client = new Client();
                    $data = [
                        'shop_object_id' => $shopObjectId,
                        'type' => $job['type'],
                        'object_id' => intval($job['object_id']),
                        'response' => json_decode($job['response_result'], true),
                    ];
                    $url = $webHookUrl . "?token=" . $webHookUrlSecret;
                    $response = $client->post($url, [
                        'body' => json_encode($data),
                        'timeout' => 60,
                    ]);
                    $statusCode = $response->getStatusCode();
                    $responseContent = $response->getBody()->getContents();
                    $responseData = json_decode($responseContent, true);
                    $ixpfResponse = [
                        'status_code' => $statusCode,
                        'response_body' => $responseData,
                        'webhook_payload' => $data
                    ];
                    if ($statusCode >= 200 && $statusCode < 300 && isset($responseData['success']) && $responseData['success']) {
                        $this->updateStatus(intval($job['entity_id']), IxpfPhotoStudioQueue::STATUS_COMPLETED);
                        $this->updateIXPFResponse(intval($job['entity_id']), $ixpfResponse);
                        $this->logger->writeDaily("PhotoStudio Webhook Dispatch: Successfully dispatched job ID " . $job['entity_id']);
                    } else {
                        $this->updateStatus(intval($job['entity_id']), IxpfPhotoStudioQueue::STATUS_WEBHOOK_FAILED);
                        $this->updateIXPFResponse(intval($job['entity_id']), $ixpfResponse);
                        $this->logger->writeDaily("PhotoStudio Webhook Dispatch: Failed to dispatch job ID " . $job['entity_id'] . " with status code " . $statusCode);
                    }

                    $connection->commit();
                } catch (Exception $e) {
                    $connection->rollBack();
                    $this->updateStatus(intval($job['entity_id']), IxpfPhotoStudioQueue::STATUS_WEBHOOK_FAILED);
                    $this->logger->writeDaily("PhotoStudio Webhook Dispatch Job Error: " . $e->getMessage());
                    //-- Alert via Slack with error details
                    $this->slackNotifier->alert("PhotoStudio Webhook Dispatch Failed", [
                        [
                            'title' => 'Error Details',
                            'value' => json_encode([
                                'job_id'     => $job['entity_id'] ?? null,
                                'product_id'  => $job['object_id'] ?? null,
                                'type'       => $job['type'] ?? null,
                                'error'      => $e->getMessage(),
                            ], JSON_PRETTY_PRINT),
                            'short' => false,
                        ],
                    ]);
                    continue;
                }
            }
            return true;
        } catch (Exception $e) {
            $this->logger->writeDaily("PhotoStudio Webhook Dispatch Error: " . $e->getMessage());
            return false;
        }

    }

    /**
     * Save In Queue
     *
     * @param string $type
     * @param int $typeEntityId
     * @param array $data
     * @return true
     */
    public function saveInQueue(string $type, int $typeEntityId, array $data = []): true
    {
        $connection = $this->resourceConnection->getConnection();
        try {
            /*if (empty($data)) {
                throw new RuntimeException('Data array is empty');
            }*/
            $connection->beginTransaction();

            $tableName = $this->resourceConnection->getTableName(IxpfPhotoStudioQueue::TABLE);
            $bucketImageTable = $this->resourceConnection->getTableName("ixpf_photo_studio_bucket_images");
            $connection->insert(
                $tableName,
                [
                    'type' => $type,
                    'object_id' => $typeEntityId,
                    'payload' => json_encode($data),
                    'status' => IxpfPhotoStudioQueue::STATUS_PENDING,
                ]
            );
            $insertedId = $connection->lastInsertId();

            foreach ($data as $image) {
                if (isset($image['id']) && $image['id'] == "new_image") {
                    $connection->insert(
                        $bucketImageTable,
                        [
                            'photo_studio_queue_id' => $insertedId,
                            'bucket_image_path' => $image['bucket_url'] ?? null,
                            'status' => 'pending'
                        ]
                    );
                }
            }

            $connection->commit();
            return true;
        } catch (Exception $exception) {
            $connection->rollBack();
            $this->logger->writeException($exception, 'PhotoStudio Save In Queue Error');
            throw new RuntimeException('Could not save in PhotoStudio queue.' . $exception->getMessage());
        }
    }

    /**
     * @param int $limit
     * @param bool $forceUpdate
     * @return array
     */
    public function getPendingQueueJob(int $limit = 1, bool $forceUpdate = false): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(IxpfPhotoStudioQueue::TABLE);
            $select = $connection->select()
                ->from(['psq' => $tableName])
                ->where('psq.status = ?', IxpfPhotoStudioQueue::STATUS_PENDING)
                ->order('psq.created_at ASC')
                ->limit($limit);
            if ($forceUpdate) {
                $select->forUpdate();
            }

            $result = $connection->fetchAll($select);
            return $result ?: [];
        } catch (Exception $exception) {
            $this->logger->writeException($exception, 'PhotoStudio Get Pending Queue Job Error');
            throw new RuntimeException('Could not get pending PhotoStudio queue job.' . $exception->getMessage());
        }
    }

    /**
     * @param int $limit
     * @param bool $forceUpdate
     * @return array
     */
    public function getFailedByMagentoQueueJob(int $limit = 1, bool $forceUpdate = false): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(IxpfPhotoStudioQueue::TABLE);
            $select = $connection->select()
                ->from(['psq' => $tableName])
                ->where('psq.status = ?', IxpfPhotoStudioQueue::STATUS_MAGENTO_FAILED)
                ->order('psq.created_at ASC')
                ->limit($limit);
            if ($forceUpdate) {
                $select->forUpdate();
            }

            $result = $connection->fetchAll($select);
            return $result ?: [];
        } catch (Exception $exception) {
            $this->logger->writeException($exception, 'PhotoStudio Get Failed By Magento Queue Job Error');
            throw new RuntimeException('Could not get Failed By Magento PhotoStudio queue job.' . $exception->getMessage());
        }
    }

    /**
     * @param int $limit
     * @param string $status
     * @return array
     */
    public function getWebhookQueueJob(int $limit = 1, string $status = IxpfPhotoStudioQueue::STATUS_READY_FOR_WEBHOOK): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(IxpfPhotoStudioQueue::TABLE);
            $select = $connection->select()
                ->from(['psq' => $tableName])
                ->where('psq.status = ?', $status)
                ->order('psq.created_at ASC')
                ->limit($limit)
                ->forUpdate();

            $result = $connection->fetchAll($select);
            return $result ?: [];
        } catch (Exception $exception) {
            $this->logger->writeDaily('PhotoStudio Get Webhook Queue Job Error: ' . $exception->getMessage());
            throw new RuntimeException('Could not get PhotoStudio webhook queue job.' . $exception->getMessage());
        }
    }

    /**
     * @param int $entityId
     * @param string $status
     * @return true
     */
    public function updateStatus(int $entityId, string $status): true
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(IxpfPhotoStudioQueue::TABLE);
            $connection->update(
                $tableName,
                [
                    'status' => $status,
                    'updated_at' => new Zend_Db_Expr('NOW()')
                ],
                ['entity_id = ?' => $entityId]
            );
            return true;
        } catch (Exception $exception) {
            $this->logger->writeException($exception, 'PhotoStudio Update Status Error');
            throw new RuntimeException('Could not update PhotoStudio queue job status.' . $exception->getMessage());
        }
    }

    /**
     * @param int $entityId
     * @param array $responseData
     * @return true
     */
    public function updateResponseResult(int $entityId, array $responseData): true
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(IxpfPhotoStudioQueue::TABLE);
            $connection->update(
                $tableName,
                [
                    'response_result' => json_encode($responseData),
                    'updated_at' => new Zend_Db_Expr('NOW()')
                ],
                ['entity_id = ?' => $entityId]
            );
            return true;
        } catch (Exception $exception) {
            $this->logger->writeDaily('PhotoStudio Update Response Result Error: ' . $exception->getMessage());
            throw new RuntimeException('Could not update PhotoStudio queue job response result.' . $exception->getMessage());
        }
    }

    /**
     * @param int $entityId
     * @param array $responseData
     * @return true
     */
    public function updateIXPFResponse(int $entityId, array $responseData): true
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(IxpfPhotoStudioQueue::TABLE);
            $connection->update(
                $tableName,
                [
                    'ixpf_response' => json_encode($responseData),
                    'updated_at' => new Zend_Db_Expr('NOW()')
                ],
                ['entity_id = ?' => $entityId]
            );
            return true;
        } catch (Exception $exception) {
            $this->logger->writeDaily('PhotoStudio Update IXPF Response Error: ' . $exception->getMessage());
            throw new RuntimeException('Could not update PhotoStudio queue job IXPF response.' . $exception->getMessage());
        }
    }

    /**
     * @param $productId
     * @param $imagesData
     * @return array
     */
    public function manageGalleryImages($productId, $imagesData): array
    {
        $errors = [];
        $uploadErrors = [];
        try {
            $product = $this->productRepository->getById($productId, true, 0, true);

            $galleryImages = $product->getMediaGallery('images');
            $alreadyExistMediaIds = [];
            if (is_array($galleryImages) && count($galleryImages)) {
                $alreadyExistMediaIds = array_keys($galleryImages);
            }

            $newImageUploads = [];
            $payloadImageIds = [];
            if (!empty($imagesData) && is_array($imagesData)) {
                foreach ($imagesData as $image) {
                    if (isset($image['id']) && $image['id'] == "new_image" && isset($image['bucket_url'])) {
                        $newImageUploads[] = $image;
                        continue;
                    }
                    if (isset($image['id']) && !in_array($image['id'], ["new_image", "tmp"])) {
                        $payloadImageIds[] = $image['id'];
                    }
                }
            }

            //-- Remove images not in payload
            $removeImageIds = array_diff($alreadyExistMediaIds, $payloadImageIds);
            $removeErrors = $this->removeImagesFromProduct($product->getSku(), $removeImageIds, intval($productId));
            $errors = array_merge($errors, $removeErrors);
            if (count($removeImageIds) > 0) {
                //-- Reload product after removals
                $product = $this->productRepository->getById($productId, true, 0, true);
            }

            //-- Update existing images information
            if (count($imagesData) > 0 && count($newImageUploads) == 0) {
                $updateErrors = $this->updateExistingImageInformation($product, $imagesData);
                $errors = array_merge($errors, $updateErrors);
            }
            if (!count($newImageUploads)) {
                //$this->productRepository->save($product);
                if (count($imagesData) > 0) {
                    $product = $product->save();
                }

                return [
                    'success' => true,
                    'details' => [
                        'gallery_images' => count($imagesData) > 0 ? $this->getGalleryImages($product->getId()) : [],
                        'upload_errors' => $uploadErrors,
                        'others_errors' => $errors
                    ]
                ];
            }

            //-- Validate Google Bucket configuration
            if (!$this->productHelper->getEnableGoogleBucketConfig()) {
                throw new Exception('Google Bucket is not enabled.');
            }
            if ($this->productHelper->getGoogleBucketConfigJsonFile() == "") {
                throw new Exception('Google Bucket credentials are not set.');
            }
            if ($this->productHelper->getGoogleBucketName() == "") {
                throw new Exception('Google Bucket name is not set.');
            }

            $uploadResult = $this->uploadNewImages($product, $newImageUploads);
            $uploadErrors = $uploadResult['errors'] ?? [];
            $newImageUploadErrors = $uploadResult['newImageUploadErrors'] ?? [];
            $errors = array_merge($errors, $uploadErrors);
            $uploadErrors = array_merge($uploadErrors, $newImageUploadErrors);

            //-- Update existing images information after new uploads
            $updateErrors = $this->updateExistingImageInformation($product, $imagesData);
            $errors = array_merge($errors, $updateErrors);

            $product->save();

            return [
                'success' => true,
                'details' => [
                    'gallery_images' => $this->getGalleryImages($product->getId()),
                    'upload_errors' => $uploadErrors,
                    'others_errors' => $errors
                ]
            ];
        } catch (Exception $e) {
            $this->logger->writeException($e, 'PhotoStudio Manage Gallery Images - Product Load Error');
            $errors[] = 'Could not load product ID ' . $productId . '. ' . $e->getMessage();
            return [
                'success' => false,
                'details' => [
                    'upload_errors' => $uploadErrors,
                    'others_errors' => $errors
                ]
            ];
        }

    }

    public function getGalleryImages($productId): array
    {
        $galleryImages = [];
        try {
            $product = $this->productRepository->getById($productId);
            $productGalleryImages = $product->getMediaGalleryEntries();
            foreach ($productGalleryImages as $image) {
                $galleryImages[] = [
                    'id'    => (int) $image->getId(),
                    'path'  => $image->getFile(),
                    'type' => $image->getMediaType(),
                    'url' => $this->productHelper->getImageUrl($image->getFile()),
                    'label' => $image->getLabel(),
                    'position' => $image->getPosition(),
                    'disabled' => $image->getDisabled(),
                    'roles' => $image->getTypes(),
                ];
            }
        } catch (Exception $e) {
            $this->logger->writeException($e, 'PhotoStudio Get Gallery Images Error - Product ID: ' . $productId);
        }
        return $galleryImages;
    }

    /**
     * @param string $sku
     * @param array $imageIds
     * @param int $productId
     * @return array
     */
    private function removeImagesFromProduct(string $sku, array $imageIds, int $productId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $errors = [];
        if (count($imageIds) > 0) {
            foreach ($imageIds as $removeImageId) {
                try {
                    $mediaGallery = $connection->fetchOne("SELECT value FROM catalog_product_entity_media_gallery WHERE value_id = {$removeImageId}");
                    if ($mediaGallery) {
                        // Clear every image-role varchar value pointing at this
                        // file (image, small_image, thumbnail, swatch_image).
                        foreach ($this->getImageRoleAttributeIds() as $attrId) {
                            $connection->delete(
                                'catalog_product_entity_varchar',
                                [
                                    'attribute_id = ?' => $attrId,
                                    'entity_id = ?'    => $productId,
                                    'value = ?'        => $mediaGallery
                                ]
                            );
                        }
                    }

                    $this->productAttributeMediaGalleryManagement->remove($sku, $removeImageId);
                } catch (Exception $e) {
                    $this->logger->writeException($e, 'PhotoStudio Remove Image From Product Error - SKU: ' . $sku . ' Image ID: ' . $removeImageId);
                    $errors[] = 'Could not remove image ID ' . $removeImageId . ' from product SKU ' . $sku . '. ' . $e->getMessage();
                }
            }
        }
        return $errors;
    }

    /**
     * @param Product $product
     * @param array $newImages
     * @return array
     */
    private function uploadNewImages(Product &$product, array $newImages): array
    {
        $errors = [];
        $newImageUploadErrors = [];
        putenv("GOOGLE_APPLICATION_CREDENTIALS={$this->productHelper->getGoogleBucketConfigJsonFile()}");
        $storage = new StorageClient();
        $bucket = $storage->bucket($this->productHelper->getGoogleBucketName());

        foreach ($newImages as $image) {
            $uploadPath = null;
            try {
                if (!isset($image['bucket_url'])) {
                    $newImageUploadErrors[] = array_merge($image, ['error' => 'Missing bucket_url']);
                    continue;
                }

                //-- Prepare local upload path
                $bucketMediaDirectory = $this->productHelper->getGoogleBucketImageInitDownloadPath();
                $filename = basename($image['bucket_url']);

                //-- Sanitize filename to ensure it's valid
                $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
                $uploadPath = $bucketMediaDirectory . $filename;

                //-- Ensure directory exists with proper permissions
                $dir = dirname($uploadPath);
                if (!is_dir($dir)) {
                    if (!mkdir($dir, 0775, true)) {
                        throw new Exception('Failed to create directory: ' . $dir);
                    }
                }

                //-- Download image from GCS
                $object = $bucket->object($image['bucket_url']);
                //-- Check if object exists in bucket
                if (!$object->exists()) {
                    throw new Exception('Object does not exist in bucket: ' . $image['bucket_url']);
                }
                $object->downloadToFile($uploadPath, [
                    'restOptions' => [
                        'verify' => false,
                        'timeoutMs' => 30000
                    ]
                ]);

                //-- Verify file was downloaded successfully
                if (!file_exists($uploadPath)) {
                    throw new Exception('File was not downloaded successfully');
                }

                $filesize = filesize($uploadPath);
                if ($filesize == 0) {
                    throw new Exception('Downloaded file is empty or unreadable');
                }

                //-- Set proper file permissions
                //chmod($uploadPath, 0664);

                //-- Verify it's a valid image with supported MIME type
                $imageInfo = @getimagesize($uploadPath);
                if ($imageInfo === false) {
                    throw new Exception('Downloaded file is not a valid image');
                }

                // Validate MIME type
                $allowedMimeTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
                $mimeType = $imageInfo['mime'] ?? mime_content_type($uploadPath);

                if (!in_array($mimeType, $allowedMimeTypes)) {
                    throw new Exception('Unsupported image format: ' . $mimeType);
                }

                //-- Additional validation: check if image can be read by GD/Imagick
                if (function_exists('imagecreatefromstring')) {
                    $imageContent = file_get_contents($uploadPath);
                    $testImage = @imagecreatefromstring($imageContent);
                    if ($testImage === false) {
                        throw new Exception('Image is corrupted or cannot be processed by GD library');
                    }
                    unset($testImage);
                }

                $roles = [];
                if (isset($image['role']) && is_array($image['role']) && count($image['role'])) {
                    $roles = $image['role'];
                }

                $disabled = false;
                if (isset($image['disabled'])) {
                    $disabled = $image['disabled'] == 1;
                }


                //-- Add image to Magento product
                $product->addImageToMediaGallery(
                    $uploadPath,
                    $roles,
                    false,
                    false
                );
                //-- Update media gallery entry (position, label, roles)
                $gallery = $product->getMediaGalleryEntries();

                if (!empty($gallery)) {
                    $lastEntryKey = array_key_last($gallery);
                    $lastEntry = $gallery[$lastEntryKey];

                    if ($lastEntry) {
                        $lastEntry->setPosition($image['position'] ?? 0);
                        $lastEntry->setLabel($image['label'] ?? '');
                        $lastEntry->setTypes($roles);
                        $lastEntry->setDisabled($disabled);

                        $product->setMediaGalleryEntries($gallery);
                    }
                }


                //-- Clean up downloaded file after successful processing
                 if (file_exists($uploadPath)) {
                     @unlink($uploadPath);
                 }

            } catch (Exception $e) {
                $errorMessage = $e->getMessage();
                $this->logger->writeException(
                    $e,
                    'PhotoStudio Upload New Image Error - Product ID: ' . $product->getId() .
                    ' - Image: ' . ($image['bucket_url'] ?? 'unknown') .
                    ' - Path: ' . ($uploadPath ?? 'not set')
                );

                $errors[] = 'Could not upload new image for product ID ' . $product->getId() . '. ' . $errorMessage;
                $newImageUploadErrors[] = array_merge($image, [
                    'error' => $errorMessage,
                ]);

                //-- Clean up file on error
                if (isset($uploadPath) && file_exists($uploadPath)) {
                     @unlink($uploadPath);
                }
                continue;
            }
        }

        return [
            'errors' => $errors,
            'newImageUploadErrors' => $newImageUploadErrors
        ];
    }

    /**
     * Update existing images (label, position, roles)
     *
     * @param Product $product
     * @param array $imagesData
     * @return array
     * @throws LocalizedException
     */
    private function updateExistingImageInformation(Product &$product, array $imagesData): array
    {

        $errors = [];
        if (empty($imagesData)) {
            return $errors;
        }

        $gallery = $product->getMediaGalleryEntries();
        foreach ($gallery as $entry) {
            try {
                foreach ($imagesData as $img) {
                    if (isset($img['id']) && $img['id'] == $entry->getId()) {
                        $entry->setLabel($img['label'] ?? '');
                        $entry->setPosition($img['position'] ?? 0);

                        /*if (isset($img['role']) && is_array($img['role'])) {
                            $entry->setTypes($img['role']);
                        }*/
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
                        if (isset($img['disabled'])) {
                            $entry->setDisabled($img['disabled'] == 1);
                        }
                    }
                }
            } catch (Exception $e) {
                $this->logger->writeDaily('Error updating existing image information: ' . $e->getMessage());
                $errors[] = 'Could not update existing image ID ' . ($image['id'] ?? '') . '. ' . $e->getMessage();
                continue;
            }
        }
        $product->setMediaGalleryEntries($gallery);

        return $errors;
    }

}