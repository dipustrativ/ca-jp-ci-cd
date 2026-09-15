<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Computes `jpy_lowest_sale_price` from a product's cost:
 *
 *     cost * 1.5 + 100 * 1.05
 *
 * The attribute is global (`is_global = 1`), so the value is only ever read
 * from and written to the default scope, store 0.
 *
 * @package IXPF_CarpetAvenueJP
 */

declare(strict_types=1);

namespace IXPF\CarpetAvenueJP\Model\Product;

use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class JpyLowestSalePrice
{
    public const ATTRIBUTE_CODE = 'jpy_lowest_sale_price';

    /** The attribute is global, so everything happens at the default scope. */
    private const STORE_ID = 0;

    /** Fixed formula. Not configurable by design — the ERP relies on it. */
    private const COST_MULTIPLIER = 1.5;
    private const SHIPPING_BASE = 100.0;
    private const TAX_MULTIPLIER = 1.05;

    /** Working precision for the decimal arithmetic. */
    private const SCALE = 10;

    public function __construct(
        private readonly ProductAction $productAction,
        private readonly ResourceConnection $resourceConnection,
        private readonly EavConfig $eavConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The value a given cost produces, rounded to the cent.
     *
     * Decimal arithmetic, not float: `133.19 * 1.5` evaluates to
     * 199.78499999999996 in binary floating point, which rounds the half-cent
     * tie at 304.785 *down* to 304.78. bcadd truncates at the scale it is
     * given, so adding half a cent first carries the tie upwards.
     */
    public function calculate(float $cost): float
    {
        $goods = bcmul($this->decimal($cost), $this->decimal(self::COST_MULTIPLIER), self::SCALE);
        $shipping = bcmul($this->decimal(self::SHIPPING_BASE), $this->decimal(self::TAX_MULTIPLIER), self::SCALE);
        $total = bcadd($goods, $shipping, self::SCALE);

        return (float)bcadd($total, $total[0] === '-' ? '-0.005' : '0.005', 2);
    }

    /**
     * Recalculate and store the value for the given products, reading each
     * one's current cost from the catalog.
     *
     * Used by the paths that write attributes directly and therefore never
     * fire a product save observer.
     *
     * @param int[] $productIds
     */
    public function updateForProducts(array $productIds): void
    {
        $costs = $this->costsFor($productIds);
        if ($costs === []) {
            return;
        }

        // Group by value so products sharing a cost cost one statement, not one
        // each. Keyed by string: PHP truncates float array keys to int.
        $groups = [];
        foreach ($costs as $productId => $cost) {
            $groups[number_format($this->calculate($cost), 6, '.', '')][] = $productId;
        }

        foreach ($groups as $value => $ids) {
            try {
                $this->productAction->updateAttributes($ids, [self::ATTRIBUTE_CODE => (float)$value], self::STORE_ID);
            } catch (\Exception $e) {
                $this->logger->error(
                    '[IXPF_CarpetAvenueJP] jpy_lowest_sale_price update failed for '
                    . count($ids) . ' products: ' . $e->getMessage()
                );
            }
        }
    }

    /**
     * Current cost per product, default scope, skipping anything without a
     * usable cost — there is nothing to compute from.
     *
     * @param int[] $productIds
     * @return array<int, float>
     */
    private function costsFor(array $productIds): array
    {
        $costAttributeId = $this->costAttributeId();
        if ($productIds === [] || $costAttributeId === null) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('catalog_product_entity_decimal'), ['entity_id', 'value'])
            ->where('attribute_id = ?', $costAttributeId)
            ->where('store_id = ?', self::STORE_ID)
            ->where('value > 0')
            ->where('entity_id IN (?)', $productIds);

        $costs = [];
        foreach ($connection->fetchAll($select) as $row) {
            $costs[(int)$row['entity_id']] = (float)$row['value'];
        }

        return $costs;
    }

    private function costAttributeId(): ?int
    {
        try {
            $id = (int)$this->eavConfig->getAttribute('catalog_product', 'cost')->getId();
        } catch (\Exception $e) {
            return null;
        }

        return $id > 0 ? $id : null;
    }

    /**
     * Float to a decimal string, leaving its binary representation error behind.
     */
    private function decimal(float $value): string
    {
        return sprintf('%.6F', $value);
    }
}
