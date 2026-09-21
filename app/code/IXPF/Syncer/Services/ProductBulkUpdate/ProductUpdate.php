<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\ProductBulkUpdate;

use GuzzleHttp\Exception\GuzzleException;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\BulkUpdateRevertLogger;
use IXPF\Syncer\Model\Config\Source\AllowAttributes;
use IXPF\Syncer\Services\Notification\SlackNotifier;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Catalog\Model\Product\Action;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\HTTP\ClientInterface;
use Exception;

class ProductUpdate
{
    //-- Static fields that should never be sent to updateAttributes
    private const STATIC_ATTRIBUTES = [
        'entity_id', 'product_id', 'id', 'sku', 'type_id', 'category_ids',
        'created_at', 'updated_at', 'attribute_set_id',
        'media_gallery', 'website_ids', 'images',
        'image', 'small_image', 'thumbnail',
    ];

    //-- Fields excluded from "unknown attributes" reporting
    private const EXCLUDED_FROM_UNKNOWN = ['id', 'website_ids', 'category_ids'];

    public function __construct(
        protected ProductAction      $productAction,
        protected Action             $productActionModel,
        protected AllowAttributes    $allowAttributes,
        protected ResourceConnection $resourceConnection,
        protected ClientInterface    $httpClient,
        protected SlackNotifier $slackNotifier,
        protected BulkUpdateRevertLogger $logger,
        protected Config $config
    )
    {
    }

    /**
     * Atomically claim the next pending job to prevent race conditions
     * when multiple cron workers run simultaneously.
     * @throws Exception
     */
    public function claimNextPendingJob(): array|false
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('ixpf_product_bulk_update_queue');

        $connection->beginTransaction();
        try {
            $select = $connection->select()
                ->from($tableName)
                ->where('status = ?', QueueStatus::PENDING)
                ->order('created_at ASC')
                ->limit(1)
                ->forUpdate();
            $row = $connection->fetchRow($select);
            if (!$row) {
                $connection->commit();
                return false;
            }

            $connection->update(
                $tableName,
                ['status' => QueueStatus::PROCESSING],
                ['entity_id = ?' => $row['entity_id']]
            );

            $connection->commit();
            return $row;
        } catch (Exception $e) {
            $connection->rollBack();
            $this->logger->writeDaily("Failed to claim pending job: " . $e->getMessage(), 'error');
            throw new Exception($e->getMessage());
        }
    }

    /**
     * @param int $queueId
     * @param string $status
     * @param array $errors
     * @param array $responseResult
     * @return void
     */
    protected function updateQueueStatus(int $queueId, string $status, array $errors = [], array $responseResult = []): void
    {
        $tableName = $this->resourceConnection->getTableName('ixpf_product_bulk_update_queue');
        $this->resourceConnection->getConnection()->update(
            $tableName,
            [
                'status' => $status,
                'response_result' => !empty($responseResult) ? json_encode($responseResult) : null,
                'error_message' => $errors ? json_encode($errors) : null,
            ],
            ['entity_id = ?' => $queueId]
        );
    }

    /**
     * @param int $queueId
     * @param string|null $response
     * @param string|null $error
     * @param string $status
     * @return void
     */
    protected function saveIxpfResponse(int $queueId, ?string $response, ?string $error, string $status): void
    {
        $tableName = $this->resourceConnection->getTableName('ixpf_product_bulk_update_queue');
        $updateData = [
            'ixpf_response' => $response,
            'error_message' => $error,
        ];
        if ($status === QueueStatus::WEBHOOK_FAILED) {
            $updateData['status'] = QueueStatus::WEBHOOK_FAILED;
        }
        $this->resourceConnection->getConnection()->update(
            $tableName,
            $updateData,
            ['entity_id = ?' => $queueId]
        );
    }

    /**
     * Splits product data into:
     *  - filtered:  allowed dynamic attributes only (ready for updateAttributes)
     *  - unknown:   keys not in the allowed attribute list
     *
     * Static/structural keys are excluded from both buckets.
     */
    public function splitProductAttributes(array $productData): array
    {
        $staticLookup = array_flip(self::STATIC_ATTRIBUTES);
        $allowedLookup = array_flip(
            array_column($this->allowAttributes->toOptionArray(), 'value')
        );

        $filtered = [];
        $unknown = [];
        foreach ($productData as $key => $value) {
            if (isset($staticLookup[$key])) {
                continue; // skip structural fields entirely
            }
            if (isset($allowedLookup[$key])) {
                $filtered[$key] = $value;
            } else {
                $unknown[$key] = $value;
            }
        }

        return ['filtered' => $filtered, 'unknown' => $unknown];
    }

    /**
     * @param array $productData
     * @param int $storeId
     * @return array
     */
    protected function processProduct(array $productData, int $storeId): array
    {
        $result = [
            'success' => false,
            'product_id' => null,
            'unknown_attributes' => [],
            'error' => null,
        ];

        if (empty($productData['id'])) {
            return $result;
        }

        $productId = (int)$productData['id'];
        $websiteIds = array_map('intval', (array)($productData['website_ids'] ?? []));
        $categoryIds = array_map('intval', (array)($productData['category_ids'] ?? []));
        $result['product_id'] = $productId;

        $connection = $this->resourceConnection->getConnection();
        try {
            $connection->beginTransaction();

            //-- Split into allowed dynamic attributes vs unknown
            ['filtered' => $filteredData, 'unknown' => $unknownData] = $this->splitProductAttributes($productData);
            $result['unknown_attributes'] = array_keys($unknownData);

            if ($storeId != 0) {
                $this->deleteStoreOrphanedAttributes($connection, $productId, $storeId, $filteredData);
            }

            // special_price = 0 → set null to clear it.
            if (array_key_exists('special_price', $filteredData) && $filteredData['special_price'] == 0) {
                $filteredData['special_price'] = null;
                $this->logger->writeDaily(
                    "[INFO] Product ID {$productId}: 'special_price' was 0 — set to null to clear the special price."
                );
            }

            // price = 0 → almost certainly a bad feed value; skip to preserve existing price.
            if (array_key_exists('price', $filteredData) && $filteredData['price'] == 0) {
                unset($filteredData['price']);
                $this->logger->writeDaily(
                    "[SKIPPED] Product ID {$productId}: 'price' was 0 — ignored to prevent accidental zero-price update."
                );
            }

            if (!empty($filteredData)) {
                $this->productAction->updateAttributes([$productId], $filteredData, $storeId);
            }

            if (!empty($websiteIds)) {
                $this->syncWebsites($connection, $productId, $websiteIds);
            }

            if (!empty($categoryIds)) {
                $this->syncCategories($connection, $productId, $categoryIds);
            }

            $connection->commit();
            $result['success'] = true;

        } catch (Exception $e) {
            $connection->rollBack();
            $result['error'] = "Product ID $productId: " . $e->getMessage();
            $this->logger->writeDaily("Failed to update product ID $productId: " . $e->getMessage(), 'error');
        }

        return $result;
    }

    /**
     * Sync website assignments without full reload.
     */
    protected function syncWebsites($connection, int $productId, array $newWebsiteIds): void
    {
        $table = $connection->getTableName('catalog_product_website');
        $currentIds = array_map(
            'intval',
            $connection->fetchCol(
                $connection->select()->from($table, ['website_id'])->where('product_id = ?', $productId)
            )
        );

        $toRemove = array_diff($currentIds, $newWebsiteIds);
        $toInsert = array_diff($newWebsiteIds, $currentIds);

        if ($toRemove) {
            $this->productActionModel->updateWebsites([$productId], $toRemove, 'remove');
        }
        if ($toInsert) {
            $this->productActionModel->updateWebsites([$productId], $toInsert, 'add');
        }
    }

    /**
     * Sync category assignments using bulk INSERT/DELETE instead of row-by-row.
     */
    protected function syncCategories($connection, int $productId, array $newCategoryIds): void
    {
        $table = $connection->getTableName('catalog_category_product');
        $currentIds = array_map(
            'intval',
            $connection->fetchCol(
                $connection->select()->from($table, ['category_id'])->where('product_id = ?', $productId)
            )
        );

        $toRemove = array_diff($currentIds, $newCategoryIds);
        $toInsert = array_diff($newCategoryIds, $currentIds);

        // Single DELETE with IN() instead of one query per category
        if ($toRemove) {
            $connection->delete($table, [
                'product_id = ?' => $productId,
                'category_id IN (?)' => $toRemove,
            ]);
        }

        // Bulk INSERT instead of one query per category
        if ($toInsert) {
            $connection->insertMultiple(
                $table,
                array_map(
                    fn(int $categoryId) => ['product_id' => $productId, 'category_id' => $categoryId],
                    $toInsert
                )
            );
        }
    }

    /**
     * @param string $ixpfBatchId
     * @param int $queueId
     * @param array $resultData
     * @return void
     * @throws GuzzleException
     */
    protected function sendResultToIXPF(string $ixpfBatchId, int $queueId, array $resultData): void
    {
        $ixpfShopId = $this->config->getShopObjectId();
        $webhookUrl = $this->config->getProductWebhookUrl();
        $webhookSecret = $this->config->getProductWebhookSecret();
        $endpoint = $webhookUrl . '?token=' . $webhookSecret;
        $errorMessage = null;
        $responseBody = null;
        $status = "";
        $httpStatus = 0;

        try {
            $client = new \GuzzleHttp\Client([
                'connect_timeout' => 3,
                'timeout'         => 10,
            ]);

            $response = $client->post($endpoint, [
                'json' => [
                    'shop_object_id' => $ixpfShopId,
                    'type'           => 'revert_bulk_update',
                    'batch_id'       => $ixpfBatchId,
                    'result'         => $resultData,
                ],
            ]);

            $responseBody = (string) $response->getBody();
            $httpStatus   = $response->getStatusCode();

            // json_decode response for better error reporting if it's JSON
            $decodedResponse = json_decode($responseBody, true);
            if (isset($decodedResponse['success']) && $decodedResponse['success'] === false) {
                $status = QueueStatus::WEBHOOK_FAILED;
                $errorMessage = "IXPF responded with success=false: " . ($decodedResponse['message'] ?? 'No message');
                $this->slackNotifier->alert("Bulk Update Webhook Error: success=false", [
                    'Batch ID'      => $ixpfBatchId,
                    'Queue ID'      => $queueId,
                    'IXPF Message'  => $decodedResponse['message'] ?? 'No message',
                    'Response Body' => $responseBody,
                ]);
            }

            if ($httpStatus >= 400) {
                $status = QueueStatus::WEBHOOK_FAILED;
                $errorMessage = "HTTP {$httpStatus} from IXPF endpoint";
                $this->slackNotifier->alert("Bulk Update Webhook Error: HTTP {$httpStatus}", [
                    'Batch ID'      => $ixpfBatchId,
                    'Queue ID'      => $queueId,
                    'HTTP Status'   => (string)$httpStatus,
                    'Response Body' => $responseBody,
                ]);
            }
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            $status = QueueStatus::WEBHOOK_FAILED;
            $errorMessage = $e->getMessage();
            $this->logger->writeDaily("Connection failed to IXPF: " . $e->getMessage(), 'error');
            $this->slackNotifier->alert("Bulk Update Webhook Error: Connection Failed", [
                'Batch ID'  => $ixpfBatchId,
                'Queue ID'  => $queueId,
                'Endpoint'  => $endpoint,
                'Error'     => $e->getMessage(),
            ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $status = QueueStatus::WEBHOOK_FAILED;
            $errorMessage = $e->getMessage();
            $responseBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : null;
            $this->logger->writeDaily("Request failed to IXPF: " . $e->getMessage(), 'error');
            $this->slackNotifier->alert("Bulk Update Webhook Error: Request Failed", [
                'Batch ID'      => $ixpfBatchId,
                'Queue ID'      => $queueId,
                'Endpoint'      => $endpoint,
                'Error'         => $e->getMessage(),
                'Response Body' => $responseBody,
            ]);
        } catch (Exception $e) {
            $status = QueueStatus::WEBHOOK_FAILED;
            $errorMessage = $e->getMessage();
            $this->logger->writeDaily("Exception when sending result to IXPF: " . $e->getMessage(), 'error');
            $this->slackNotifier->alert("Bulk Update Webhook Error: Unexpected Exception", [
                'Batch ID' => $ixpfBatchId,
                'Queue ID' => $queueId,
                'Error'    => $e->getMessage(),
            ]);
        }

        $this->saveIxpfResponse($queueId, $responseBody, $errorMessage, $status);
    }

    /**
     * @return array|array[]
     * @throws Exception|GuzzleException
     */
    public function process(): array
    {
        $emptyResult = [
            'success_product_ids' => [],
            'failed_product_ids' => [],
            'unknown_attributes' => [],
            'errors' => [],
        ];

        $queueData = $this->claimNextPendingJob();
        if (!$queueData) {
            return $emptyResult;
        }

        $queueId = (int)$queueData['entity_id'];
        $ixpfBatchId = (string)($queueData['ixpf_batch_id'] ?? '');
        $storeId = (int)($queueData['store_id'] ?? 0);
        
        $products = json_decode($queueData['payload'], true);
        if (empty($products)) {
            $this->updateQueueStatus($queueId, QueueStatus::COMPLETED, ['empty product payload']);
            return $emptyResult;
        }

        $successIds = [];
        $failedIds = [];
        $unknownAttrs = [];
        $errors = [];

        foreach ($products as $productData) {
            $result = $this->processProduct($productData, $storeId);

            if ($result['success']) {
                $successIds[] = $result['product_id'];
            } else {
                if ($result['product_id']) {
                    $failedIds[] = $result['product_id'];
                }
                if ($result['error']) {
                    $errors[] = $result['error'];
                }
            }

            array_push($unknownAttrs, ...$result['unknown_attributes']);
        }

        // Filter structural keys from unknown attributes report
        $unknownAttrs = array_values(array_unique(array_filter(
            $unknownAttrs,
            fn($attr) => !in_array($attr, self::EXCLUDED_FROM_UNKNOWN, true)
        )));

        $finalStatus = $this->resolveFinalStatus($successIds, $failedIds, count($products));

        $resultData = [
            'success_product_ids' => $successIds,
            'failed_product_ids' => $failedIds,
            'unknown_attributes' => $unknownAttrs,
            'errors' => $errors,
            'status' => $finalStatus,
        ];

        $this->updateQueueStatus($queueId, $finalStatus, $errors, $resultData);
        $this->sendResultToIXPF($ixpfBatchId, $queueId, $resultData);

        return $resultData;
    }

    /**
     * Determine final queue status based on success/failure counts.
     */
    private function resolveFinalStatus(array $successIds, array $failedIds, int $total): string
    {
        if (empty($failedIds)) {
            return QueueStatus::COMPLETED;
        }

        if (count($failedIds) === $total) {
            return QueueStatus::FAILED; // all failed
        }

        return QueueStatus::FAILED; // partial failure — still mark FAILED so it's visible
    }

    /**
     * Delete store-level attribute rows for a product when those attributes are present
     * in the store but absent from the incoming feed's filtered data.
     *
     * This prevents stale store-specific overrides from persisting after a feed update
     * that no longer includes those attributes.
     *
     * Logic:
     *  1. Fetch all store-scoped attribute rows for the product via getProductStoreAttributeDetail().
     *  2. Compare the store attribute codes against the feed's filtered attribute codes.
     *  3. Any attribute that exists in the store but is missing from the feed is considered
     *     "orphaned" and its store-level row is removed from the EAV catalog table.
     *
     * Example:
     *  store attributes = ['name', 'cost', 'price']
     *  feed attributes  = ['cost', 'price']
     *  orphaned         = ['name']  ← the store-level 'name' row will be deleted
     *
     * @param AdapterInterface $connection  Active DB connection (already in a transaction).
     * @param int   $productId    The product entity ID.
     * @param int   $storeId      The store view ID (must be > 0).
     * @param array $filteredData Allowed dynamic attributes from the feed (keyed by attribute code).
     */
    protected function deleteStoreOrphanedAttributes(AdapterInterface $connection, int $productId, int $storeId, array $filteredData): void
    {
        //-- get all attributes that are related to the store scope and remove them from the update list,
        //-- since we don't want to accidentally override store-specific values when updating at a website/global level.
        $storeAttributeCodeWithValues = $this->getProductStoreAttributeDetail($productId, $storeId);

        //-- Find attributes that exist in the store but are missing from the feed's filtered data.
        //-- array_keys() is used to extract attribute codes from both arrays as values,
        //-- then array_diff() compares them to return codes present in the store but absent in the feed.
        //-- Example: store=['name','cost'] feed=['cost','price'] → missing=['name']
        $missingInStore = array_diff(
            array_keys($storeAttributeCodeWithValues),
            array_keys($filteredData)
        );

        if (empty($missingInStore)) {
            return;
        }

        $this->logger->writeDaily(
            "[INFO] Product ID {$productId} Store {$storeId}: "
            . "The following attributes exist in the store but are missing from the feed and will be deleted: "
            . implode(', ', $missingInStore)
        );

        foreach ($missingInStore as $code) {
            $attributeId = $storeAttributeCodeWithValues[$code]['attribute_id'];
            $table       = $storeAttributeCodeWithValues[$code]['table'];

            $connection->delete(
                $table,
                [
                    'entity_id = ?'    => $productId,
                    'attribute_id = ?' => $attributeId,
                    'store_id = ?'     => $storeId,
                ]
            );
        }
    }

    protected function getProductStoreAttributeDetail(int $productId, int $storeId): array
    {
        $connection   = $this->resourceConnection->getConnection();
        $backendTypes = ['varchar', 'int', 'decimal', 'text', 'datetime'];

        $result = [];

        foreach ($backendTypes as $type) {
            $table = $this->resourceConnection->getTableName("catalog_product_entity_{$type}");

            // Fetch both store 0 and store-specific rows
            // store-specific row (higher store_id) overwrites default via MAX trick
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from(
                        ['v' => $table],
                        [
                            'entity_id'    => 'v.entity_id',
                            'attribute_id' => 'v.attribute_id',
                            'value'        => 'v.value',
                            'store_id'     => 'v.store_id',
                        ]
                    )
                    ->joinInner(
                        ['a' => $this->resourceConnection->getTableName('eav_attribute')],
                        'v.attribute_id = a.attribute_id',
                        [
                            'attribute_code' => 'a.attribute_code',
                            'backend_type'   => 'a.backend_type',
                        ]
                    )
                    ->where('v.entity_id = ?', $productId)
                    ->where('v.store_id = (?)', $storeId)
            );

            // Merge: store-specific row overwrites default row
            foreach ($rows as $row) {
                $code = $row['attribute_code'];

                $result[$code] = [
                    'table'         => $table,
                    'entity_id'    => $row['entity_id'],
                    'attribute_id'   => $row['attribute_id'],
                    'attribute_code' => $code,
                    'backend_type'   => $type,
                    'value'          => $row['value'],
                    'is_store_specific' => (int)$row['store_id'] === $storeId,
                ];
            }
        }
        return $result;
    }
}