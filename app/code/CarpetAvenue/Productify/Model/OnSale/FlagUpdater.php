<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\Model\OnSale;

use CarpetAvenue\Productify\Setup\Patch\Data\AddOnSaleAttribute;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;

/**
 * Recomputes the am_on_sale flag behind the カーペット販売中 filter.
 *
 * A product is on sale when its final price is below its regular price — the
 * same rule carpetavenue.jp uses (Amasty's OnSaleProvider), so special prices,
 * catalog rules and tier prices all count. Read from catalog_product_index_price
 * for customer group 0, the logged-out shopper's view, which is what the facet
 * count on the storefront should reflect.
 *
 * Only rows whose value actually CHANGED are written. catalog_product_entity_int
 * is a subscribed table for the fulltext indexer's mview, so every write schedules
 * a reindex for that product — rewriting the whole catalogue hourly would churn
 * the search index for nothing.
 */
class FlagUpdater
{
    private const GUEST_CUSTOMER_GROUP = 0;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * @return array{on_sale: int, changed: int} how many are on sale, and how many rows moved
     */
    public function execute(): array
    {
        $connection = $this->resource->getConnection();
        $attributeId = (int)$this->eavConfig
            ->getAttribute(\Magento\Catalog\Model\Product::ENTITY, AddOnSaleAttribute::ATTRIBUTE_CODE)
            ->getAttributeId();

        $entityTable = $this->resource->getTableName('catalog_product_entity');
        $valueTable = $this->resource->getTableName('catalog_product_entity_int');
        $priceTable = $this->resource->getTableName('catalog_product_index_price');

        //  Discounted right now, per the price index.
        $onSaleIds = $connection->fetchCol(
            $connection->select()
                ->from(['p' => $priceTable], ['entity_id'])
                ->where('p.customer_group_id = ?', self::GUEST_CUSTOMER_GROUP)
                ->where('p.final_price < p.price')
                ->distinct()
        );
        $onSaleIds = array_map('intval', $onSaleIds);

        //  Flag as currently stored, default scope.
        $stored = $connection->fetchPairs(
            $connection->select()
                ->from(['v' => $valueTable], ['entity_id', 'value'])
                ->where('v.attribute_id = ?', $attributeId)
                ->where('v.store_id = ?', 0)
        );

        $allIds = $connection->fetchCol(
            $connection->select()->from(['e' => $entityTable], ['entity_id'])
        );

        $onSaleLookup = array_flip($onSaleIds);
        $insert = [];

        foreach ($allIds as $id) {
            $id = (int)$id;
            $wanted = isset($onSaleLookup[$id]) ? 1 : 0;
            $current = array_key_exists($id, $stored) ? (int)$stored[$id] : null;

            if ($current === $wanted) {
                continue;
            }

            $insert[] = [
                'attribute_id' => $attributeId,
                'store_id'     => 0,
                'entity_id'    => $id,
                'value'        => $wanted,
            ];
        }

        if ($insert !== []) {
            //  Chunked so a first run over the whole catalogue does not build one
            //  enormous statement.
            foreach (array_chunk($insert, 500) as $chunk) {
                $connection->insertOnDuplicate($valueTable, $chunk, ['value']);
            }
        }

        return ['on_sale' => count($onSaleIds), 'changed' => count($insert)];
    }
}
