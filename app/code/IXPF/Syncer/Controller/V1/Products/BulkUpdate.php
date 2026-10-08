<?php

namespace IXPF\Syncer\Controller\V1\Products;

use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\Helpers as ProductHelper;
use IXPF\Syncer\Helper\TokenValidator;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Catalog\Model\ResourceModel\Product\Action;
use Magento\Eav\Model\Config as EavConfig;
use IXPF\Syncer\Helper\Logger\ProductUpdateLogger;
use IXPF\Syncer\Services\Products\PriceAttributeGuard;


class BulkUpdate extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected ProductHelper $productHelper,
        protected Action $productAction,
        protected ResourceConnection $resourceConnection,
        protected EavConfig $eavConfig,
        protected ProductUpdateLogger $productUpdateLogger,
        protected PriceAttributeGuard $priceAttributeGuard
    )
    {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            //-- token validation check
            $this->tokenValidator();

            $store = $this->getRequest()->getParam('store');
            if (!$store) {
                $store = 0;
            }
            $rawContent = $this->getRequest()->getContent();
            $payload = json_decode($rawContent, true);
            if (empty($payload)) {
                throw new \Exception('Empty product information');
            }

            if (empty($payload['attributes']) || !is_array($payload['attributes'])) {
                throw new \Exception('Empty product attributes');
            }

            if (!count($payload['attributes'])) {
                throw new \Exception('Empty product attributes');
            }

            if (empty($payload['product_ids']) || !is_array($payload['product_ids']) || count($payload['product_ids']) < 1) {
                throw new \Exception('Empty product ids');
            }

            if (!$this->productHelper->getEnableProductBulkUpdateConfig()) {
                throw new \Exception('Product bulk update configuration disable');
            }

            //-- log payload
            $this->productUpdateLogger->writeDaily("[bulk_update] Starting bulk update products. Store ID: " . $store);
            $this->productUpdateLogger->writeDaily('[bulk_update] payload: ' . json_encode($payload));

            $updatedAttributesWithValue = [];
            $categoryIds = [];
            $allowUpdateAttributes = $this->productHelper->getProductUpdateBulkAllowAttributes();
            if (count($allowUpdateAttributes) == 0) {
                throw new \Exception('Empty allow update attributes configuration');
            }

            $notAllowUpdateAttributes = [];
            $updatedAllowAttributes = [];
            foreach ($payload['attributes'] as $key => $value) {
                if ($key == "category_ids") {
                    $categoryIds = $value;
                    continue;
                }
                if (in_array($key, $allowUpdateAttributes)) {
                    $updatedAttributesWithValue[$key] = $value;
                    $updatedAllowAttributes[] = $key;
                } else {
                    $notAllowUpdateAttributes[] = $key;
                }
            }

            // special_price 0/null → null (clear). price 0/null → dropped (bad feed).
            // Any other non-positive / non-numeric price value → reject, nothing written.
            $guard = $this->priceAttributeGuard->apply($updatedAttributesWithValue);
            if (count($guard['invalid']) > 0) {
                $this->productUpdateLogger->writeDaily(
                    '[bulk_update] [INVALID] ' . implode('; ', $guard['invalid']),
                    'error'
                );
                return $this->jsonResponse([
                    'error' => 'Invalid price data: ' . implode('; ', $guard['invalid'])
                ], 422);
            }
            $updatedAttributesWithValue = $guard['attributes'];

            if (in_array('special_price', $guard['cleared'], true)) {
                $this->productUpdateLogger->writeDaily(
                    "[bulk_update] [INFO] Bulk update: 'special_price' was 0 — set to null to clear the special price."
                );
            }

            if (in_array('price', $guard['skipped'], true)) {
                $updatedAllowAttributes = array_values(array_diff($updatedAllowAttributes, ['price']));
                $this->productUpdateLogger->writeDaily(
                    "[bulk_update] [SKIPPED] Bulk update: 'price' was 0 in the request payload — "
                    . "value ignored to prevent accidental zero-price update."
                );
            }

            if (count($notAllowUpdateAttributes) > 0) {
                $this->productUpdateLogger->writeDaily("[bulk_update] Not allowed update attributes");
                $this->productUpdateLogger->writeDaily('[bulk_update] not allowed: ' . json_encode($notAllowUpdateAttributes));
                throw new \Exception('There are attributes that are not allowed to update: ' . implode(", ", $notAllowUpdateAttributes));
            }

            if (count($allowUpdateAttributes)) {
                //-- log updated products
                $this->productUpdateLogger->writeDaily("[bulk_update] Bulk update products");
                $this->productUpdateLogger->writeDaily("[bulk_update] Bulk update products Store ID: " . $store);
                $this->productUpdateLogger->writeDaily('[bulk_update] attributes: ' . json_encode($updatedAttributesWithValue));

                // Keep every non-null value. Keep 'special_price' even when null so it is
                // written through updateAttributes (null clears the special price). A null
                // on any other attribute is dropped — never sent to updateAttributes.
                $toUpdate = array_filter(
                    $updatedAttributesWithValue,
                    fn($v, $k) => $v !== null || $k === 'special_price',
                    ARRAY_FILTER_USE_BOTH
                );

                if (!empty($toUpdate)) {
                    //-- update attributes (special_price kept even when null)
                    $this->productAction->updateAttributes($payload['product_ids'], $toUpdate, $store);

                    //-- Recalculate otto_price if price or special_price was updated
                    if (!empty(array_intersect(['price', 'special_price'], array_keys($toUpdate)))) {
                        $this->ottoPriceUpdater->updateForProducts($payload['product_ids']);
                    }
                }
            }

            //-- update websites
            if (isset($payload['attributes']['website_ids']) && is_array($payload['attributes']['website_ids']) && count($payload['attributes']['website_ids'])) {
                $connection  = $this->resourceConnection->getConnection();
                $tableName   = $connection->getTableName('catalog_product_website');
                $productIds  = array_map('intval', $payload['product_ids']);
                $websiteIds  = array_map('intval', $payload['attributes']['website_ids']);

                // Remove associations not in the new set
                $connection->delete($tableName, [
                    'product_id IN (?)'     => $productIds,
                    'website_id NOT IN (?)' => $websiteIds,
                ]);

                // Fetch existing to avoid duplicate inserts
                $existingRows = $connection->fetchAll(
                    $connection->select()
                        ->from($tableName, ['product_id', 'website_id'])
                        ->where('product_id IN (?)', $productIds)
                        ->where('website_id IN (?)', $websiteIds)
                );
                $existingMap = [];
                foreach ($existingRows as $row) {
                    $existingMap[(int)$row['product_id']][(int)$row['website_id']] = true;
                }

                // Bulk insert missing associations
                $toInsert = [];
                foreach ($productIds as $productId) {
                    foreach ($websiteIds as $websiteId) {
                        if (!isset($existingMap[$productId][$websiteId])) {
                            $toInsert[] = ['product_id' => $productId, 'website_id' => $websiteId];
                        }
                    }
                }
                if (!empty($toInsert)) {
                    $connection->insertMultiple($tableName, $toInsert);
                }
            }

            //-- updated category values
            if (is_array($categoryIds) && count($categoryIds)) {
                //-- log updated products
                $this->productUpdateLogger->writeDaily("[bulk_update] Bulk update products categories");
                $this->productUpdateLogger->writeDaily("[bulk_update] Bulk update products Store ID: " . $store);
                $this->productUpdateLogger->writeDaily('[bulk_update] category_ids: ' . json_encode($categoryIds));

                $connection  = $this->resourceConnection->getConnection();
                $tableName   = $connection->getTableName('catalog_category_product');
                $productIds  = array_map('intval', $payload['product_ids']);
                $catIds      = array_map('intval', $categoryIds);

                // Fetch all existing associations in one query
                $existingRows = $connection->fetchAll(
                    $connection->select()
                        ->from($tableName, ['product_id', 'category_id', 'position'])
                        ->where('product_id IN (?)', $productIds)
                        ->where('category_id IN (?)', $catIds)
                );
                $existingMap = [];
                foreach ($existingRows as $row) {
                    $existingMap[(int)$row['product_id']][(int)$row['category_id']] = (int)$row['position'];
                }

                // Insert new associations (position 1000) — skip already-existing rows
                $toInsert = [];
                foreach ($productIds as $productId) {
                    foreach ($catIds as $categoryId) {
                        if (!isset($existingMap[$productId][$categoryId])) {
                            $toInsert[] = [
                                'category_id' => $categoryId,
                                'product_id'  => $productId,
                                'position'    => 1000,
                            ];
                        }
                    }
                }
                if (!empty($toInsert)) {
                    $connection->insertMultiple($tableName, $toInsert);
                }

                // Update position to 1000 for existing rows that still have position = 0
                $connection->update(
                    $tableName,
                    ['position' => 1000],
                    [
                        'product_id IN (?)'  => $productIds,
                        'category_id IN (?)' => $catIds,
                        'position = ?'       => 0,
                    ]
                );
            }

            return $this->jsonResponse([
                'success' => true,
                'message' => 'Products updated successfully',
                'information' => [
                    'updated_attributes' => $updatedAllowAttributes,
                    'not_allowed_attributes' => $notAllowUpdateAttributes
                ]
            ]);

        } catch (\Exception $exception) {
            $this->productUpdateLogger->writeDaily("[bulk_update] Failed to bulk update products. Error: " . $exception->getMessage(), 'error');
            return $this->jsonResponse(['error' => $exception->getMessage()], 500);
        }
    }

    /**
     * For non-default stores: delete the store-level EAV override row for each
     * attribute so Magento falls back to the default (store 0) value.
     * This mirrors clicking "Use Default Value" in the Magento admin.
     *
     * @param array  $productIds     List of product entity IDs
     * @param array  $attributeCodes Attribute codes whose values should revert to default
     * @param int    $storeId        Must be > 0 (callers must guard against store 0)
     */
    protected function resetAttributesToDefault(array $productIds, array $attributeCodes, int $storeId): void
    {
        if ($storeId === 0 || empty($productIds) || empty($attributeCodes)) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        foreach ($attributeCodes as $attributeCode) {
            try {
                $attribute = $this->eavConfig->getAttribute('catalog_product', $attributeCode);
                if (!$attribute || !$attribute->getId()) {
                    $this->productUpdateLogger->writeDaily(
                        "[bulk_update] [WARN] resetAttributesToDefault: attribute '{$attributeCode}' not found — skipped."
                    );
                    continue;
                }

                $backendTable = $attribute->getBackendTable();
                $attributeId  = (int) $attribute->getAttributeId();

                $deleted = $connection->delete($backendTable, [
                    'attribute_id = ?' => $attributeId,
                    'store_id = ?'     => $storeId,
                    'entity_id IN (?)' => $productIds,
                ]);

                $this->productUpdateLogger->writeDaily(
                    "[bulk_update] [RESET] Attribute '{$attributeCode}' store-level override removed for "
                    . count($productIds) . " product(s) on store {$storeId} "
                    . "({$deleted} row(s) deleted) — will fall back to default (store 0)."
                );

            } catch (\Exception $e) {
                $this->productUpdateLogger->writeDaily(
                    "[bulk_update] [ERROR] resetAttributesToDefault failed for '{$attributeCode}': " . $e->getMessage(),
                    'error'
                );
            }
        }
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
