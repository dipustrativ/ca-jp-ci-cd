<?php

declare(strict_types=1);

namespace IXPF\Syncer\Services\Products;

use IXPF\Syncer\Helper\Helpers as ProductHelper;
use IXPF\Syncer\Helper\Logger\ProductUpdateLogger;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Framework\App\ResourceConnection;

/**
 * Per-product attribute, stock and source mass update.
 *
 * Each product carries its own attribute set. Every attribute code is validated
 * against ixpfng_product/product_update_config/allow_attributes before write.
 * Optional "sources" / "stock" blocks (gated by allow_stock_source) are handled
 * by StockSourceUpdater. Invalid product ids and rejected values are reported
 * per product — valid work still applies for the rest.
 *
 * Write order per row: attributes, then stock item. Source items from all rows
 * are saved in one batch after the loop (stock item saves before the source
 * save, so the legacy stock item never writes a stale qty back to "default").
 */
class AttributeMassUpdate
{
    public function __construct(
        protected ProductHelper        $productHelper,
        protected ProductAction        $productAction,
        protected ResourceConnection   $resourceConnection,
        protected ProductUpdateLogger  $logger,
        protected PriceAttributeGuard  $priceAttributeGuard,
        protected StockSourceUpdater   $stockSourceUpdater
    ) {
    }

    /**
     * @param array $products payload['products']
     * @param int $storeId
     * @return array structured response
     * @throws \Exception
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
        $allowStockSource = $this->productHelper->getProductUpdateAllowStockSource();

        $existing = $this->getExistingProducts($products);

        /** @var array<int,array> $rows row index => state, turned into results after the source save */
        $rows           = [];
        $sourceItems    = [];
        $recalcPriceIds = [];

        foreach ($products as $index => $product) {
            $productId  = isset($product['product_id']) ? (int)$product['product_id'] : 0;
            $attributes = $product['attributes'] ?? null;
            $sources    = $product['sources'] ?? null;
            $stock      = $product['stock'] ?? null;

            if (!$productId) {
                $rows[$index] = $this->rowError($productId, 'Missing product_id');
                continue;
            }

            if (empty($attributes) && empty($sources) && empty($stock)) {
                $rows[$index] = $this->rowError($productId, 'Nothing to update: provide attributes, sources or stock');
                continue;
            }

            if (!isset($existing[$productId])) {
                $rows[$index] = $this->rowError($productId, 'Product not found');
                continue;
            }

            $row = $this->rowError($productId, '');
            $issues = [];

            //-- attributes
            if (!empty($attributes)) {
                if (!is_array($attributes)) {
                    $issues[] = 'Invalid attributes';
                    $attributes = [];
                }

                $valid   = [];
                $invalid = [];
                foreach ($attributes as $code => $value) {
                    if (in_array($code, $allowAttributes, true)) {
                        $valid[$code] = $value;
                    } else {
                        $invalid[] = $code;
                    }
                }
                $row['invalid_attributes'] = $invalid;

                $guard = $this->applyPriceGuards($productId, $valid);
                if (count($guard['invalid']) > 0) {
                    // a bad price blocks the whole row — no stock/source write either
                    $rows[$index] = $this->rowError($productId, implode('; ', $guard['invalid']), $invalid);
                    continue;
                }
                $valid = $guard['attributes'];

                if (count($valid) === 0) {
                    $issues[] = count($invalid) > 0
                        ? 'No valid attributes. Not allowed: ' . implode(', ', $invalid)
                        : 'No valid attributes to update';
                } else {
                    if (count($invalid) > 0) {
                        $issues[] = 'Ignored not-allowed: ' . implode(', ', $invalid);
                    }
                    try {
                        $this->productAction->updateAttributes([$productId], $valid, $storeId);
                        $row['updated_attributes'] = array_keys($valid);

                        if (!empty(array_intersect(['price', 'special_price'], array_keys($valid)))) {
                            $recalcPriceIds[] = $productId;
                        }
                    } catch (\Exception $e) {
                        $this->logger->writeDaily("[attribute_mass_updated] [ERROR] Mass update product {$productId}: " . $e->getMessage(), 'error');
                        $issues[] = $e->getMessage();
                    }
                }
            }

            //-- stock + sources
            if (!empty($sources) || !empty($stock)) {
                if (!$allowStockSource) {
                    if (!empty($stock)) {
                        $row['invalid_stock'] = ['update disabled in configuration'];
                    }
                    if (!empty($sources)) {
                        $row['invalid_sources'] = [['source_code' => '', 'reason' => 'update disabled in configuration']];
                    }
                } else {
                    $sku    = $existing[$productId]['sku'];
                    $typeId = $existing[$productId]['type_id'];

                    if (!empty($stock)) {
                        try {
                            $stockResult = $this->stockSourceUpdater->updateStockItem($sku, $stock);
                            $row['updated_stock'] = $stockResult['updated'];
                            $row['invalid_stock'] = $stockResult['invalid'];
                        } catch (\Exception $e) {
                            $this->logger->writeDaily("[attribute_mass_updated] [ERROR] Stock update product {$productId}: " . $e->getMessage(), 'error');
                            $row['invalid_stock'] = ['Save error: ' . $e->getMessage()];
                        }
                    }

                    if (!empty($sources)) {
                        $built = $this->stockSourceUpdater->buildSourceItems($sku, $typeId, $sources);
                        $row['invalid_sources'] = $built['invalid'];
                        $sourceItems[$index]    = $built['items'];
                    }
                }
            }

            $row['issues'] = $issues;
            $rows[$index]  = $row;
        }

        //-- one source save for the whole request
        $sourceErrors = $this->stockSourceUpdater->saveSourceItems($sourceItems);
        foreach ($sourceItems as $index => $items) {
            if (isset($sourceErrors[$index])) {
                foreach (array_keys($items) as $code) {
                    $rows[$index]['invalid_sources'][] = ['source_code' => (string)$code, 'reason' => $sourceErrors[$index]];
                }
            } else {
                $rows[$index]['updated_sources'] = array_map('strval', array_keys($items));
            }
        }

        //-- results
        $results      = [];
        $updatedCount = 0;
        $errorCount   = 0;
        $partialCount = 0;
        foreach ($rows as $row) {
            $issues = $row['issues'] ?? null;
            unset($row['issues']);
            if ($issues === null) {
                // early row error — message already set
                $errorCount++;
                $results[] = $row;
                continue;
            }

            foreach ($row['invalid_stock'] as $reason) {
                $issues[] = 'Stock ' . $reason;
            }
            foreach ($row['invalid_sources'] as $failure) {
                $issues[] = ($failure['source_code'] !== '' ? "Source {$failure['source_code']}: " : 'Sources: ') . $failure['reason'];
            }

            $written = !empty($row['updated_attributes']) || !empty($row['updated_stock']) || !empty($row['updated_sources']);
            $row['success'] = $written;
            if ($written) {
                $updatedCount++;
                if (!empty($row['invalid_stock']) || !empty($row['invalid_sources'])) {
                    $partialCount++;
                }
                $row['message'] = count($issues) > 0 ? 'Updated. ' . implode('; ', $issues) : 'Updated successfully';
            } else {
                $errorCount++;
                $row['message'] = implode('; ', $issues);
            }
            $results[] = $row;
        }

        return [
            'success' => $errorCount === 0 && $partialCount === 0,
            'message' => sprintf(
                'Mass attribute update processed. %d updated, %d with errors.',
                $updatedCount,
                $errorCount
            ),
            'summary' => [
                'total'   => count($products),
                'updated' => $updatedCount,
                'errors'  => $errorCount,
                'partial' => $partialCount,
            ],
            'results' => $results,
        ];
    }

    /**
     * special_price 0/null → null (clear). price 0/null → drop (bad feed).
     * Any other non-positive / non-numeric price value → invalid, product not written.
     */
    protected function applyPriceGuards(int $productId, array $valid): array
    {
        $guard = $this->priceAttributeGuard->apply($valid);

        foreach ($guard['invalid'] as $message) {
            $this->logger->writeDaily("[attribute_mass_updated] [INVALID] Product {$productId}: {$message}", 'error');
        }
        if (count($guard['invalid']) > 0) {
            return $guard;
        }
        if (in_array('special_price', $guard['cleared'], true)) {
            $this->logger->writeDaily("[attribute_mass_updated] [INFO] Product {$productId}: special_price 0 -> null (cleared).");
        }
        if (in_array('price', $guard['skipped'], true)) {
            $this->logger->writeDaily("[attribute_mass_updated] [SKIPPED] Product {$productId}: price 0 ignored.");
        }
        return $guard;
    }

    /**
     * Single query — which requested ids actually exist, with sku + type for
     * the stock/source writes. Avoids N+1 loads.
     *
     * @return array<int,array{sku:string,type_id:string}> id => product
     */
    protected function getExistingProducts(array $products): array
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
            ->from($table, ['entity_id', 'sku', 'type_id'])
            ->where('entity_id IN (?)', $ids);

        $found = [];
        foreach ($connection->fetchAll($select) as $row) {
            $found[(int)$row['entity_id']] = ['sku' => (string)$row['sku'], 'type_id' => (string)$row['type_id']];
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
            'updated_sources'    => [],
            'invalid_sources'    => [],
            'updated_stock'      => [],
            'invalid_stock'      => [],
            'message'            => $message,
        ];
    }
}
