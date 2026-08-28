<?php

declare(strict_types=1);

namespace IXPF\Syncer\Services\Products;

use IXPF\Syncer\Helper\Helpers as ProductHelper;
use IXPF\Syncer\Helper\Logger\ProductUpdateLogger;
use IXPF\Syncer\Services\OttoPrice\OttoPriceUpdater;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Framework\App\ResourceConnection;

/**
 * Per-product attribute mass update.
 *
 * Each product carries its own attribute set. Every attribute code is validated
 * against ixpfng_product/product_update_config/allow_attributes before write.
 * Invalid product ids and not-allowed attributes are reported per product —
 * valid work still applies for the rest.
 */
class AttributeMassUpdate
{
    public function __construct(
        protected ProductHelper        $productHelper,
        protected ProductAction        $productAction,
        protected ResourceConnection   $resourceConnection,
        protected ProductUpdateLogger  $logger,
        protected OttoPriceUpdater     $ottoPriceUpdater
    ) {
    }

    /**
     * @param array $products payload['products']
     * @param int   $storeId
     * @return array structured response
     */
    public function process(array $products, int $storeId = 0): array
    {
        if (!$this->productHelper->getEnableProductUpdateConfig()) {
            throw new \Exception('Product update configuration disabled');
        }

        $allowAttributes = $this->productHelper->getProductUpdateAllowAttributes();
        if (count(array_filter($allowAttributes)) === 0) {
            throw new \Exception('Empty allow update attributes configuration');
        }

        $existingIds = $this->getExistingProductIds($products);

        $results       = [];
        $updatedCount  = 0;
        $errorCount    = 0;
        $recalcPriceIds = [];

        foreach ($products as $index => $product) {
            $productId  = isset($product['product_id']) ? (int)$product['product_id'] : 0;
            $attributes = $product['attributes'] ?? null;

            if (!$productId) {
                $errorCount++;
                $results[] = $this->rowError($productId, 'Missing product_id');
                continue;
            }

            if (empty($attributes) || !is_array($attributes)) {
                $errorCount++;
                $results[] = $this->rowError($productId, 'Empty attributes');
                continue;
            }

            if (!isset($existingIds[$productId])) {
                $errorCount++;
                $results[] = $this->rowError($productId, 'Product not found');
                continue;
            }

            $valid      = [];
            $invalid    = [];
            foreach ($attributes as $code => $value) {
                if (in_array($code, $allowAttributes, true)) {
                    $valid[$code] = $value;
                } else {
                    $invalid[] = $code;
                }
            }

            $valid = $this->applyPriceGuards($productId, $valid);

            if (count($valid) === 0) {
                $errorCount++;
                $msg = count($invalid) > 0
                    ? 'No valid attributes. Not allowed: ' . implode(', ', $invalid)
                    : 'No valid attributes to update';
                $results[] = $this->rowError($productId, $msg, $invalid);
                continue;
            }

            try {
                $this->productAction->updateAttributes([$productId], $valid, $storeId);

                if (!empty(array_intersect(['price', 'special_price'], array_keys($valid)))) {
                    $recalcPriceIds[] = $productId;
                }

                $updatedCount++;
                $results[] = [
                    'product_id'         => $productId,
                    'success'            => true,
                    'updated_attributes' => array_keys($valid),
                    'invalid_attributes' => $invalid,
                    'message'            => count($invalid) > 0
                        ? 'Updated. Ignored not-allowed: ' . implode(', ', $invalid)
                        : 'Updated successfully',
                ];
            } catch (\Exception $e) {
                $errorCount++;
                $this->logger->writeDaily("[ERROR] Mass update product {$productId}: " . $e->getMessage());
                $results[] = $this->rowError($productId, $e->getMessage(), $invalid);
            }
        }

        if (!empty($recalcPriceIds)) {
            $this->ottoPriceUpdater->updateForProducts(array_values(array_unique($recalcPriceIds)));
        }

        return [
            'success' => $errorCount === 0,
            'message' => sprintf(
                'Mass attribute update processed. %d updated, %d with errors.',
                $updatedCount,
                $errorCount
            ),
            'summary' => [
                'total'   => count($products),
                'updated' => $updatedCount,
                'errors'  => $errorCount,
            ],
            'results' => $results,
        ];
    }

    /**
     * price = 0 → drop (bad feed). special_price = 0 → null (clear).
     */
    protected function applyPriceGuards(int $productId, array $valid): array
    {
        if (array_key_exists('special_price', $valid) && $valid['special_price'] == 0) {
            $valid['special_price'] = null;
            $this->logger->writeDaily("[INFO] Product {$productId}: special_price 0 -> null (cleared).");
        }
        if (array_key_exists('price', $valid) && $valid['price'] == 0) {
            unset($valid['price']);
            $this->logger->writeDaily("[SKIPPED] Product {$productId}: price 0 ignored.");
        }
        return $valid;
    }

    /**
     * Single query — which requested ids actually exist. Avoids N+1 loads.
     *
     * @return array<int,bool> id => true
     */
    protected function getExistingProductIds(array $products): array
    {
        $ids = [];
        foreach ($products as $product) {
            if (!empty($product['product_id'])) {
                $ids[] = (int)$product['product_id'];
            }
        }
        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $table      = $connection->getTableName('catalog_product_entity');
        $select     = $connection->select()
            ->from($table, ['entity_id'])
            ->where('entity_id IN (?)', $ids);

        $found = [];
        foreach ($connection->fetchCol($select) as $id) {
            $found[(int)$id] = true;
        }
        return $found;
    }

    protected function rowError(int $productId, string $message, array $invalid = []): array
    {
        return [
            'product_id'         => $productId,
            'success'            => false,
            'updated_attributes' => [],
            'invalid_attributes' => $invalid,
            'message'            => $message,
        ];
    }
}
