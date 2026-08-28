<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\OttoPrice;

use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;

/**
 * Recalculates and persists otto_price after price changes that bypass the product save observer.
 *
 * Formula (mirrors CarpetAvenue\OttoMods\Helper\OttoHelper::generateOttoPrice):
 *   otto_price = round(price - (price * pricing_pct / 100), 0)
 *   otto_price = min(otto_price, special_price)  — when special_price exists and is > 0
 *
 * Always reads from and writes to global scope (store 0).
 */
class OttoPriceUpdater
{
    public function __construct(
        protected ProductAction $productAction,
        protected ResourceConnection $resourceConnection,
        protected EavConfig $eavConfig
    ) {}

    /**
     * Recalculate otto_price for the given product IDs.
     * Safe to call after productAction->updateAttributes() — reads fresh values from DB.
     */
    public function updateForProducts(array $productIds): void
    {
        if (empty($productIds)) {
            return;
        }

        $productIds = array_unique(array_map('intval', $productIds));
        $connection = $this->resourceConnection->getConnection();

        $priceAttrId        = (int)$this->eavConfig->getAttribute('catalog_product', 'price')->getId();
        $specialPriceAttrId = (int)$this->eavConfig->getAttribute('catalog_product', 'special_price')->getId();
        $pricingAttrId      = (int)$this->eavConfig->getAttribute('catalog_product', 'pricing')->getId();

        if (!$priceAttrId || !$pricingAttrId) {
            return;
        }

        $decimalTable = $connection->getTableName('catalog_product_entity_decimal');
        $intTable     = $connection->getTableName('catalog_product_entity_int');
        $optionTable  = $connection->getTableName('eav_attribute_option');
        $optionValTbl = $connection->getTableName('eav_attribute_option_value');

        // Fetch base prices (global scope)
        $prices = $connection->fetchPairs(
            $connection->select()
                ->from($decimalTable, ['entity_id', 'value'])
                ->where('attribute_id = ?', $priceAttrId)
                ->where('store_id = 0')
                ->where('entity_id IN (?)', $productIds)
        );

        // Fetch special prices (global scope)
        $specialPrices = $specialPriceAttrId ? $connection->fetchPairs(
            $connection->select()
                ->from($decimalTable, ['entity_id', 'value'])
                ->where('attribute_id = ?', $specialPriceAttrId)
                ->where('store_id = 0')
                ->where('entity_id IN (?)', $productIds)
        ) : [];

        // Fetch pricing percentage from option label (pricing is a select attribute)
        $pricingPcts = $connection->fetchPairs(
            $connection->select()
                ->from(['cpe' => $intTable], ['entity_id' => 'cpe.entity_id'])
                ->joinInner(
                    ['eao' => $optionTable],
                    'eao.option_id = cpe.value AND eao.attribute_id = ' . $pricingAttrId,
                    []
                )
                ->joinInner(
                    ['eaov' => $optionValTbl],
                    'eaov.option_id = eao.option_id AND eaov.store_id = 0',
                    ['pct' => 'eaov.value']
                )
                ->where('cpe.attribute_id = ?', $pricingAttrId)
                ->where('cpe.store_id = 0')
                ->where('cpe.entity_id IN (?)', $productIds)
        );

        foreach ($productIds as $productId) {
            $price = isset($prices[$productId]) ? (float)$prices[$productId] : null;
            if (!$price) {
                continue;
            }

            $pricingPct   = (int)($pricingPcts[$productId] ?? 0);
            $specialPrice = (isset($specialPrices[$productId]) && $specialPrices[$productId] !== null)
                ? (float)$specialPrices[$productId]
                : null;

            $ottoPrice = round($price - ($price * $pricingPct / 100), 0);
            if ($specialPrice && $specialPrice > 0) {
                $ottoPrice = min($ottoPrice, $specialPrice);
            }

            $this->productAction->updateAttributes([$productId], ['otto_price' => $ottoPrice], 0);
        }
    }
}
