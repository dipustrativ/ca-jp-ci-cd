<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Order
 * @package   CarpetAvenue_ExportImport
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\ExportImport\Model\Product;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Store\Model\Store;

/**
 * Resolves the **default-scope** (store 0) product name for a set of SKUs.
 *
 * This store has one store view, `jp`, whose `name` values are Japanese; the
 * English names live at default scope. `sales_order_item.name` is a snapshot of
 * whatever the storefront showed at order time (Japanese), so the English name
 * has to come from the catalog.
 *
 * Values are looked up in batches and cached for the life of the instance, so a
 * SKU repeated across orders costs one query per export run, not per row.
 */
class DefaultNameProvider
{
    /** @var array<string, string> sku => default-scope name ('' when unknown) */
    private array $cache = [];

    public function __construct(
        private readonly CollectionFactory $productCollectionFactory,
    ) {
    }

    /**
     * @param string[] $skus
     * @return array<string, string> sku => default-scope name
     */
    public function getBySkus(array $skus): array
    {
        $skus = array_filter(array_unique($skus), static fn ($sku) => $sku !== '');
        $missing = array_diff($skus, array_keys($this->cache));

        if ($missing !== []) {
            $collection = $this->productCollectionFactory->create();
            $collection->setStoreId(Store::DEFAULT_STORE_ID)
                ->addAttributeToSelect('name')
                ->addFieldToFilter('sku', ['in' => array_values($missing)]);

            foreach ($collection as $product) {
                $this->cache[(string)$product->getSku()] = (string)$product->getName();
            }
            // Remember the misses too, so an unknown SKU is not re-queried per row.
            foreach ($missing as $sku) {
                $this->cache[$sku] ??= '';
            }
        }

        $result = [];
        foreach ($skus as $sku) {
            $result[$sku] = $this->cache[$sku] ?? '';
        }
        return $result;
    }

    /**
     * Drop the cache — call between export runs so a renamed product is picked up.
     */
    public function reset(): void
    {
        $this->cache = [];
    }
}
