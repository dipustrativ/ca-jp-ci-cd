<?php

namespace IXPF\Syncer\Services\Purchase;

use Exception;
use IXPF\Syncer\Model\ResourceModel\IxpfPurchaseBatch\CollectionFactory as BatchCollectionFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use IXPF\Syncer\Helper\Logger\PurchaseLogger;
use IXPF\Syncer\Helper\ProductPurchase;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;
use Magento\InventoryApi\Api\SourceItemsDeleteInterface;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;

class Purchase
{
    /**
     * Seconds to wait for another request holding the same SKU before giving up
     */
    private const SKU_LOCK_TIMEOUT = 30;

    /**
     * In-memory cache for loaded products (keyed by SKU)
     * Prevents duplicate DB loads within the same request
     *
     * @var array<string, Product|false>
     */
    private array $productCache = [];

    /**
     * In-memory cache for source items (keyed by "sourceCode::sku")
     *
     * @var array<string, SourceItemInterface>
     */
    private array $sourceItemCache = [];

    /**
     * Configured required attribute codes (create only), loaded once per request
     *
     * @var string[]|null
     */
    private ?array $requiredAttributes = null;

    public function __construct(
        protected ProductAttribute              $productAttribute,
        protected CategoryResolver              $categoryResolver,
        protected ResourceConnection            $resourceConnection,
        protected BatchCollectionFactory        $batchCollectionFactory,
        protected ProductPurchase               $productPurchaseHelper,
        protected PurchaseLogger                $purchaseLogger,
        protected ProductFactory                $productFactory,
        protected Product                       $product,
        protected ProductRepositoryInterface    $productRepository,     // FIX #2: use repository (cached)
        protected SourceItemInterfaceFactory    $sourceItemFactory,
        protected SourceItemsSaveInterface      $sourceItemsSaveInterface,
        protected SearchCriteriaBuilder         $searchCriteriaBuilder,
        protected SourceItemRepositoryInterface $sourceItemRepository,
        protected SourceItemsDeleteInterface    $sourceItemsDelete,
        protected StoreValueWriter              $storeValueWriter,
        protected LockManagerInterface          $lockManager
    )
    {
    }

    /**
     * @param string $triggerFrom
     * @return void
     * @throws Exception
     * @deprecated
     */
    public function run(string $triggerFrom = "terminal"): void
    {
        // Intentionally left empty – kept for backward compatibility
    }

    /**
     * @param array $purchaseData
     * @param string $processStatus
     * @param int|null $purchaseId
     * @return int
     */
    public function purchaseOrderCreateOrUpdate(
        array  $purchaseData = [],
        string $processStatus = 'pending',
        ?int   $purchaseId = null
    ): int
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('ixpf_purchase_orders');

        $purchaseData['process_status'] = $processStatus;

        if ($purchaseId) {
            $connection->update($tableName, $purchaseData, ['entity_id = ?' => $purchaseId]);
            return $purchaseId;
        }

        $connection->insert($tableName, $purchaseData);
        return (int)$connection->lastInsertId($tableName);
    }

    /**
     * @param array $purchaseItemData
     * @param string|null $sku
     * @param int|null $purchaseId
     * @return void
     */
    public function purchaseOrderItemCreateOrUpdate(
        array   $purchaseItemData = [],
        ?string $sku = null,
        ?int    $purchaseId = null
    ): void
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('ixpf_purchase_order_items');

        if ($sku && $purchaseId) {
            $connection->update($tableName, $purchaseItemData, [
                'purchase_order_id = ?' => $purchaseId,
                'sku = ?' => $sku,
            ]);
            return;
        }

        // One item row per (ixpf_purchase_id, sku): a resent SKU refreshes the existing row and
        // re-points it at the current purchase order, so the status updates above (keyed on
        // purchase_order_id + sku) land on it instead of matching nothing.
        $select = $connection->select()
            ->from(['ipo' => $tableName], ['entity_id'])
            ->where('ipo.ixpf_purchase_id = ?', $purchaseItemData['ixpf_purchase_id'])
            ->where('ipo.sku = ?', $purchaseItemData['sku'])
            ->order('ipo.entity_id ASC')
            ->limit(1);

        $existingItemId = $connection->fetchOne($select);
        if ($existingItemId) {
            $purchaseItemData['error_message'] = null;
            $connection->update($tableName, $purchaseItemData, ['entity_id = ?' => (int)$existingItemId]);
            return;
        }

        $connection->insert($tableName, $purchaseItemData);
    }

    /**
     * Entry point called from outside: loads attributes then processes products.
     *
     * @param array $products
     * @param int $purchaseOrderId
     * @return array
     */
    public function productCreateInit(array $products, int $purchaseOrderId): array
    {
        $this->loadAttributes();
        return $this->processProducts($products, $purchaseOrderId);
    }

    /**
     * @return void
     */
    public function loadAttributes(): void
    {
        $this->productAttribute->getAttributeList();
    }


    /**
     * @param array $products
     * @param int $purchaseOrderId
     * @return array
     */
    private function processProducts(array $products, int $purchaseOrderId): array
    {
        $success = 0;
        $failed = 0;
        $productResponse = [];
        $purchaseOrder = $this->getPurchaseOrderById($purchaseOrderId);

        foreach ($products as $product) {
            // Normalise the SKU once and use it everywhere. Magento trims the SKU on save but
            // looks it up untrimmed, so " RUG-1" would miss the existing "RUG-1" and save a
            // second product with the same SKU.
            $product['sku'] = $this->normalizeSku($product['sku'] ?? '');

            try {
                if ($product['sku'] === '') {
                    throw new Exception('Product sku is empty');
                }

                $createOrUpdateProductData = [];
                $purchaseQty = 0;
                $ongoing = false; //-- optional for others magento2 versions
                $stockSource = '';

                foreach ($product as $key => $value) {
                    if (in_array($key, ['status', 'attribute_set', 'visibility'])) {
                        $createOrUpdateProductData[$key === 'attribute_set' ? 'attribute_set_id' : $key] =  intval($value);
                        continue;
                    }

                    if (in_array($key, ['category_ids', 'sku', 'website_ids', 'store_id', 'store_ids', 'categories'])) {
                        $createOrUpdateProductData[$key] = in_array($key, ['category_ids', 'website_ids', 'store_ids', 'categories'])
                            ? explode(',', $value)
                            : $value;
                        continue;
                    }

                    $attributeCodeExists = $this->productAttribute->getAttribute($key);
                    if ($attributeCodeExists) {
                        $createOrUpdateProductData[$key] = $this->productAttribute->getAttributeValue($key, $value);
                    }
                }

                // "categories" = list of "A/B/C" path strings from the API.
                // Resolve to ids (creating missing levels), merge into category_ids.
                if (!empty($createOrUpdateProductData['categories'])) {
                    $resolvedCategoryIds = $this->categoryResolver->resolvePaths(
                        $createOrUpdateProductData['categories']
                    );
                    if ($resolvedCategoryIds) {
                        $existingCategoryIds = $createOrUpdateProductData['category_ids'] ?? [];
                        $createOrUpdateProductData['category_ids'] = array_values(array_unique(
                            array_merge($existingCategoryIds, $resolvedCategoryIds)
                        ));
                    }
                }
                unset($createOrUpdateProductData['categories']);

                // The category list replaces the product's categories on update, so drop blanks /
                // non-ids; an empty result leaves categories untouched rather than unassigning all.
                if (isset($createOrUpdateProductData['category_ids'])) {
                    $categoryIds = array_values(array_unique(array_filter(
                        array_map('intval', (array)$createOrUpdateProductData['category_ids'])
                    )));
                    if ($categoryIds) {
                        $createOrUpdateProductData['category_ids'] = $categoryIds;
                    } else {
                        unset($createOrUpdateProductData['category_ids']);
                    }
                }

                $stockSource = $product['stock_source'] ?? '';
                $purchaseQty = intval($product['qty'] ?? ($product['qty_ordered'] ?? 0));
                $ongoing = isset($product['ongoing_create']) && intval($product['ongoing_create']) === 1;

                // Per-store-view translations (name/description text + select option labels).
                $storeValues = (isset($product['store_values']) && is_array($product['store_values']))
                    ? $product['store_values']
                    : [];

                // stock_source is either a single source code ("default") paired with the
                // top-level qty, or a list of {source, qty} pairs. Normalise both to a list
                // of ['sources' => code, 'qty' => int] entries; 'qty' stays unset when the
                // payload omits it so assignMSI() keeps the source item's current quantity.
                $sourceInfo = [];
                if (is_array($stockSource)) {
                    foreach ($stockSource as $stock) {
                        if (!is_array($stock)) {
                            // Plain list of codes: ["hamburg", "tokyo"]
                            $stock = ['source' => $stock];
                        }
                        // IXPF sends the per-entry key as "stock_source"; "source" is accepted too.
                        $sourceCode = trim((string)($stock['source'] ?? $stock['stock_source'] ?? ''));
                        if ($sourceCode === '') {
                            continue;
                        }
                        $entry = ['sources' => $sourceCode];
                        if (isset($stock['qty'])) {
                            $entry['qty'] = intval($stock['qty']);
                        }
                        $sourceInfo[] = $entry;
                    }

                    // No top-level qty with a per-source list: record their sum on the item.
                    if ($purchaseQty === 0) {
                        $purchaseQty = array_sum(array_column($sourceInfo, 'qty'));
                    }
                } elseif (trim((string)$stockSource) !== '') {
                    $entry = ['sources' => trim((string)$stockSource)];
                    if ($purchaseQty > 0) {
                        $entry['qty'] = $purchaseQty;
                    }
                    $sourceInfo[] = $entry;
                }

                // Create the purchase order item record (initial state)
                $purchaseOrderItem = [
                    'purchase_order_id' => $purchaseOrderId,
                    'ixpf_purchase_id' => $purchaseOrder['ixpf_purchase_id'] ?? '',
                    'sku' => $product['sku'],
                    'behavior' => 'create',
                    'name' => $product['name'] ?? '',
                    'price' => $product['price'] ?? 0,
                    'cost' => $product['cost'] ?? 0,
                    'process_status' => 'processing',
                    'qty' => $purchaseQty,
                    'attribute_data' => json_encode($product),
                    'ongoing_create' => $ongoing ? 1 : 0,
                ];

                try {
                    $behavior = 'create';
                    $this->purchaseOrderItemCreateOrUpdate($purchaseOrderItem);

                    // Serialise create-or-update per SKU across requests: an IXPF retry that
                    // overlaps the original call would otherwise see "not found" in both and
                    // create the product twice.
                    $lockName = 'ixpf_purchase_sku_' . hash('sha256', mb_strtolower($product['sku']));
                    if (!$this->lockManager->lock($lockName, self::SKU_LOCK_TIMEOUT)) {
                        throw new Exception('SKU is being processed by another request, retry later');
                    }
                    try {
                        $productData = $this->updateOrCreateProduct(
                            $product['sku'],
                            $createOrUpdateProductData,
                            $sourceInfo,
                            $behavior,
                            $storeValues,
                            $product
                        );
                    } finally {
                        $this->lockManager->unlock($lockName);
                    }

                    // Mark item as completed / ready for ongoing
                    $this->purchaseOrderItemCreateOrUpdate(
                        [
                            'process_status' => $ongoing ? 'ready_for_ongoing' : 'completed',
                            'behavior' => $behavior,
                            // an update may omit name — log the product's actual name
                            'name' => (string)$productData->getName(),
                        ],
                        $product['sku'],
                        $purchaseOrderId
                    );
                } catch (Exception $exception) {
                    $this->purchaseOrderItemCreateOrUpdate(
                        [
                            'error_message' => $exception->getMessage(),
                            'process_status' => 'failed',
                        ],
                        $product['sku'],
                        $purchaseOrderId
                    );
                    throw new Exception(
                        "Product SKU {$product['sku']} creation failed: " . $exception->getMessage()
                    );
                }

                $productResponse[$product['sku']] = [
                    'status' => true,
                    'id' => intval($productData->getId()),
                    'message' => "Product $behavior successfully",
                ];
                $success++;

            } catch (Exception $exception) {
                $productResponse[$product['sku']] = [
                    'status' => false,
                    'error' => $exception->getMessage(),
                ];
                $failed++;
            }
        }

        return [
            'success' => $success,
            'failed' => $failed,
            'product_response' => $productResponse,
        ];
    }

    /**        raw Model::load() so repeated SKU lookups hit memory, not the DB.
     *
     * @param string $sku
     * @param array $productData
     * @param array $purchaseInfo
     * @param string|null $behavior (passed by reference so caller knows create vs update)
     * @return Product
     * @throws Exception
     */
    private function updateOrCreateProduct(
        string  $sku,
        array   $productData,
        array   $purchaseInfo = [],
        ?string &$behavior = null,
        array   $storeValues = [],
        array   $rawProduct = []
    ): Product
    {
        $hasProduct = $this->getProduct($sku);

        if ($hasProduct) {
            $productInstance = $hasProduct;
            $behavior = 'update';
        } else {
            $this->assertCreatable($rawProduct);
            $productInstance = $this->productFactory->create();
            $behavior = 'create';
            // Defaults for new products only — on update an omitted field keeps its current
            // value (a partial update must not disable the product or reset its visibility).
            $productData += [
                'attribute_set_id' => 4,
                'visibility' => 4,
                'status' => 2,
            ];
        }

        // name is optional on update; an empty one is ignored rather than blanking the product.
        if ($behavior === 'update' && array_key_exists('name', $productData)
            && trim((string)$productData['name']) === ''
        ) {
            unset($productData['name']);
        }

        // attribute_set_id is a smallint: an unknown id would be saved truncated (999999 →
        // 65535) and leave a product that can no longer be loaded.
        if (isset($productData['attribute_set_id'])
            && !$this->isProductAttributeSet((int)$productData['attribute_set_id'])
        ) {
            throw new Exception("Unknown product attribute_set {$productData['attribute_set_id']}");
        }


        if (!isset($productData['url_key'])) {
            if ($behavior !== 'update' && in_array(0, $productData['store_ids'] ?? [])) {
                $productData['url_key'] = $this->makeUrl($productData);
            }
        }

        // special_price = 0 → treat as "remove special price" by setting null.
        if (array_key_exists('special_price', $productData) && $productData['special_price'] == 0) {
            $productData['special_price'] = null;
            $this->purchaseLogger->writeDaily(
                "[INFO] Product SKU {$sku}: 'special_price' was 0 — set to null to clear the special price."
            );
        }

        foreach ($productData as $key => $value) {
            // Do not overwrite immutable fields on existing products
            if ($behavior === 'update' && in_array($key, ['sku', 'ean_barcode', 'tepp_barcode'])) {
                continue;
            }
            if (in_array($key, ['store_id', 'store_ids'])) {
                continue;
            }
            if ($key == "tepp_barcode" && $value) {
               $value = (string) $value;
               $value = (strpos($value, '0') === 0) ? $value : '0' . $value;
            }
            $productInstance->setData($key, $value);
        }

        // Purchase API only: the payload's categories are the full list (replace). Magento merges
        // category_ids into the loaded category_links otherwise, so a moved product would stay in
        // its old categories too. Without links, SaveHandler syncs to category_ids (keeping
        // positions of categories that stay) and deletes the rest.
        if ($behavior === 'update' && isset($productData['category_ids'])) {
            $productInstance->getExtensionAttributes()->setCategoryLinks(null);
        }

        $product = $productInstance->save();

        // Magento renames a colliding new SKU to "<sku>-1" instead of failing. Reaching this
        // means a duplicate slipped past the lookup above — record it loudly.
        if ($behavior === 'create' && $product->getSku() !== $sku) {
            $this->purchaseLogger->writeDaily(
                "[ERROR] Product SKU {$sku} already existed; Magento saved the new product as "
                . "SKU {$product->getSku()} (id {$product->getId()}). Duplicate needs cleanup.",
                'error'
            );
        }

        // Bust our local cache so the next getProduct() call for this SKU
        // returns the freshly-saved instance.
        unset($this->productCache[$sku]);

        // category position: Magento sets position=0 on save; update to 1000 for new zero-position rows
        if (!empty($productData['category_ids'])) {
            $this->fixCategoryPositions((int)$product->getId(), $productData['category_ids']);
        }

        // Assign MSI source items (non-fatal: log and continue on failure).
        // A single ['sources' => ...] map is still accepted for callers that pass one source.
        $sources = isset($purchaseInfo['sources']) ? [$purchaseInfo] : $purchaseInfo;

        // "default" is only cleared when the payload itself doesn't assign it — otherwise a
        // later non-default source would delete the default qty this same batch just wrote.
        $keepDefault = false;
        foreach ($sources as $source) {
            if (isset($source['sources']) && trim(strtolower((string)$source['sources'])) === 'default') {
                $keepDefault = true;
                break;
            }
        }

        foreach ($sources as $source) {
            if (!isset($source['sources'])) {
                continue;
            }
            try {
                $this->assignMSI($product, $source, !$keepDefault);
            } catch (Exception $exception) {
                $this->purchaseLogger->writeExceptionDaily($exception);
                $this->purchaseLogger->writeDaily(
                    "MSI assignment failed for product SKU: {$sku} – " . $exception->getMessage(),
                    'error'
                );
            }
        }

        // Per-store-view translations via raw SQL (non-fatal: log and continue on failure).
        if (!empty($storeValues)) {
            try {
                $this->storeValueWriter->applyStoreValues((int)$product->getId(), $rawProduct, $storeValues);
            } catch (Exception $exception) {
                $this->purchaseLogger->writeExceptionDaily($exception);
                $this->purchaseLogger->writeDaily(
                    "store_values apply failed for product SKU: {$sku} – " . $exception->getMessage(),
                    'error'
                );
            }
        }

        return $product;
    }

    /**
     * Set position=1000 for any category-product rows that were written with position=0 during product save.
     * Only touches rows where position is still 0 — existing non-zero positions are left untouched.
     */
    private function fixCategoryPositions(int $productId, array $categoryIds): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $connection->getTableName('catalog_category_product'),
            ['position' => 1000],
            [
                'product_id = ?'     => $productId,
                'category_id IN (?)' => array_map('intval', $categoryIds),
                'position = ?'       => 0,
            ]
        );
    }

    /**
     * @param Product $product
     * @param array $purchaseInfo
     * @param bool $removeDefault Clear the "default" source item when assigning a specific source
     * @return void
     * @throws Exception
     */
    private function assignMSI(Product $product, array $purchaseInfo = [], bool $removeDefault = true): void
    {
        if (!isset($purchaseInfo['sources'])) {
            throw new Exception('No source defined for MSI assignment');
        }

        try {
            $sku = $product->getSku();
            $sourceCode = trim(strtolower((string)$purchaseInfo['sources']));

            if ($sourceCode === '') {
                throw new Exception('Empty source code for MSI assignment');
            }

            $sourceItem = $this->getStockSourceItem($sourceCode, $sku);

            if (!isset($purchaseInfo['qty'])) {
                $purchaseInfo['qty'] = $sourceItem->getQuantity() > 0
                    ? $sourceItem->getQuantity()
                    : 0;
            }

            $sourceItem->setQuantity($purchaseInfo['qty']);
            $sourceItem->setStatus($purchaseInfo['qty'] > 0 ? 1 : 0);

            $this->sourceItemsSaveInterface->execute([$sourceItem]);

            // Remove from the "default" source when a specific source is used — unless the
            // same payload also assigns "default" (see updateOrCreateProduct).
            if ($removeDefault && $sourceCode !== 'default') {
                $defaultSourceItem = $this->getStockSourceItem('default', $sku);
                $this->sourceItemsDelete->execute([$defaultSourceItem]);
                // Evict the cached "default" entry since it no longer exists.
                unset($this->sourceItemCache["default::{$sku}"]);
            }
        } catch (Exception $exception) {
            throw new Exception('MSI assignment failed: ' . $exception->getMessage());
        }
    }

    /**
     * @param string $sourceCode
     * @param string $sku
     * @return SourceItemInterface
     * @throws LocalizedException
     */
    public function getStockSourceItem(string $sourceCode, string $sku): SourceItemInterface
    {
        $cacheKey = "{$sourceCode}::{$sku}";

        if (isset($this->sourceItemCache[$cacheKey])) {
            return $this->sourceItemCache[$cacheKey];
        }

        $search = $this->searchCriteriaBuilder
            ->addFilter(SourceItemInterface::SOURCE_CODE, $sourceCode)
            ->addFilter(SourceItemInterface::SKU, $sku)
            ->create();

        $searchResult = $this->sourceItemRepository->getList($search);
        $count = $searchResult->getTotalCount();

        if ($count === 1) {
            $sourceItem = current($searchResult->getItems());
        } elseif ($count > 1) {
            throw new LocalizedException(__('Too many source items found for %1', $sku));
        } else {
            $sourceItem = $this->sourceItemFactory->create();
            $sourceItem->setSourceCode($sourceCode);
            $sourceItem->setSku($sku);
        }

        $this->sourceItemCache[$cacheKey] = $sourceItem;
        return $sourceItem;
    }

    /**
     * @param string $sku
     * @return Product|false
     */
    protected function getProduct(string $sku): Product|false
    {
        if (empty($sku)) {
            return false;
        }

        // Only hits are cached: a miss is re-checked, since another request may have created
        // the SKU since (callers hold the SKU lock while re-checking).
        if (!empty($this->productCache[$sku])) {
            return $this->productCache[$sku];
        }

        try {
            /** @var Product $product */
            $product = $this->productRepository->get($sku, false, 0);
            $this->productCache[$sku] = $product->getId() ? $product : false;
        } catch (NoSuchEntityException $e) {
            // A product that exists but fails to load (broken attribute set, etc.) must not
            // be treated as missing — the create path would save a duplicate "<sku>-1".
            if ($this->product->getIdBySku($sku)) {
                throw new Exception("Product SKU {$sku} exists but could not be loaded: " . $e->getMessage());
            }
            $this->productCache[$sku] = false;
        }

        return $this->productCache[$sku];
    }

    /**
     * A new product needs a name and every configured required attribute; an update needs
     * neither (only the fields sent are changed).
     *
     * @param array $rawProduct
     * @return void
     * @throws Exception
     */
    private function assertCreatable(array $rawProduct): void
    {
        if ($this->requiredAttributes === null) {
            $this->requiredAttributes = array_values(array_filter(array_map(
                'trim',
                $this->productPurchaseHelper->getRequiredAttributes()
            )));
        }

        $missing = [];
        if (trim((string)($rawProduct['name'] ?? '')) === '') {
            $missing[] = 'name';
        }
        // A required attribute needs a value: absent, null, "" or [] count as missing (0 is a value).
        foreach ($this->requiredAttributes as $attributeCode) {
            $value = $rawProduct[$attributeCode] ?? null;
            if ($value === null || $value === [] || (is_scalar($value) && trim((string)$value) === '')) {
                $missing[] = $attributeCode;
            }
        }

        if ($missing) {
            throw new Exception(
                'New product is missing required fields: ' . implode(', ', array_unique($missing))
            );
        }
    }

    /**
     * @param int $attributeSetId
     * @return bool
     */
    private function isProductAttributeSet(int $attributeSetId): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['eas' => $this->resourceConnection->getTableName('eav_attribute_set')], ['attribute_set_id'])
            ->join(
                ['eet' => $this->resourceConnection->getTableName('eav_entity_type')],
                'eet.entity_type_id = eas.entity_type_id',
                []
            )
            ->where('eet.entity_type_code = ?', Product::ENTITY)
            ->where('eas.attribute_set_id = ?', $attributeSetId);

        return (bool)$connection->fetchOne($select);
    }

    /**
     * Trim ASCII whitespace plus NBSP and the full-width (ideographic) space.
     *
     * @param mixed $sku
     * @return string
     */
    private function normalizeSku(mixed $sku): string
    {
        $sku = (string)$sku;
        // preg_replace() returns null on invalid UTF-8; fall back to a plain trim.
        return preg_replace('/^[\s\x{00A0}\x{3000}]+|[\s\x{00A0}\x{3000}]+$/u', '', $sku) ?? trim($sku);
    }

    /**
     * @param int $id
     * @param string $status
     * @return void
     */
    private function updateBatchStatus(int $id, string $status): void
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $connection->update(
                $this->resourceConnection->getTableName('ixpf_purchases_batch'),
                ['status' => $status],
                ['id = ?' => $id]
            );
        } catch (Exception $exception) {
            // Silent fail – status update is non-critical
        }
    }

    /**
     * @param array $magentoResponse
     * @return array
     * @throws Exception
     */
    private function webhookCall(array $magentoResponse): array
    {
        if (!$this->productPurchaseHelper->isWebhookEnable()) {
            throw new Exception('Webhook call is not enabled');
        }
        $webhookUrl = $this->productPurchaseHelper->getWebhookUrl();
        if (!$webhookUrl) {
            throw new Exception('Webhook call url not defined');
        }
        $webhookSecret = $this->productPurchaseHelper->getWebhookSecret();
        if (!$webhookSecret) {
            throw new Exception('Webhook call secret not defined');
        }
        $shopObjectId = $this->productPurchaseHelper->getShopObjectId();
        if (!$shopObjectId) {
            throw new Exception('Ixpf ShopObjectId not defined');
        }

        $data = json_encode([
            'shop_object_id' => $shopObjectId,
            'type' => 'purchase_product',
            'response' => $magentoResponse,
        ]);

        $url = $webhookUrl . '?token=' . $webhookSecret;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);   // FIX #5: fail fast on connect
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);           // FIX #5: max 30 s for full response

        $response = curl_exec($ch);
        $result = [];

        if (curl_errno($ch)) {
            $result['success'] = false;
            $result['error'] = curl_error($ch);
        } else {
            $result['response'] = $response;
            $returnData = json_decode($response, true);
            $result['success'] = isset($returnData['success']) && $returnData['success'];
        }

        unset($ch);
        return $result;
    }

    /**
     * @param int $id
     * @return mixed
     */
    protected function getPurchaseOrderById(int $id): mixed
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('ixpf_purchase_orders');

        $select = $connection->select()
            ->from(['ipo' => $tableName])
            ->where('ipo.entity_id = ?', $id)
            ->limit(1);

        return $connection->fetchRow($select);
    }

    /**
     * @param array $productInfo
     * @return string
     */
    protected function makeUrl(array $productInfo): string
    {
        $seoCategoryName = isset($productInfo['seo_category_id'])
            ? $this->getSeoCategoryName($productInfo['seo_category_id'])
            : $this->getGoogleFeedCategoryName($productInfo['google_feed_category'] ?? null);

        // Optional parts may be missing from the payload; trim the dashes they leave behind.
        return trim(preg_replace(
            '/[^a-z0-9-]+/',
            '-',
            strtolower(
                $seoCategoryName . '-' .
                ($productInfo['name'] ?? '') . '-' .
                number_format((float)($productInfo['width'] ?? 0), 0, '', '') . 'x' .
                number_format((float)($productInfo['length'] ?? 0), 0, '', '') . '-' .
                $productInfo['sku']
            )
        ), '-');
    }

    /**
     * @param mixed $optionId
     * @return string
     */
    protected function getGoogleFeedCategoryName($optionId): string
    {
        if (empty($optionId)) {
            return '';
        }

        $connection = $this->resourceConnection->getConnection();
        $eavAttributeId = $connection->fetchOne(
            "SELECT attribute_id FROM `eav_attribute` WHERE `attribute_code` = 'google_feed_category'"
        );

        if (empty($eavAttributeId)) {
            return '';
        }

        return (string)$connection->fetchOne(
            "SELECT eaov.value
               FROM `eav_attribute_option_value` AS eaov
              INNER JOIN catalog_product_entity_int AS cpei
                      ON cpei.attribute_id = :attribute_id
                     AND cpei.value = eaov.option_id
              WHERE eaov.`option_id` = :option_id
                AND eaov.`store_id` = 0
              LIMIT 1",
            [
                'attribute_id' => (int)$eavAttributeId,
                'option_id' => (int)$optionId,
            ]
        );
    }

    /**
     * @param mixed $categoryId
     * @return string
     */
    protected function getSeoCategoryName($categoryId): string
    {
        $connection = $this->resourceConnection->getConnection();

        return (string)$connection->fetchOne(
            "SELECT ccev.`value`
               FROM `catalog_category_entity_varchar` AS ccev
              INNER JOIN `eav_attribute` AS ea ON ea.attribute_id = ccev.attribute_id
              WHERE ea.attribute_code = 'name'
                AND ea.entity_type_id = (
                        SELECT entity_type_id FROM `eav_entity_type`
                         WHERE entity_type_code = 'catalog_category'
                    )
                AND ccev.entity_id = :category_id
                AND ccev.store_id  = 0
              LIMIT 1",
            ['category_id' => (int)$categoryId]
        );
    }
}
