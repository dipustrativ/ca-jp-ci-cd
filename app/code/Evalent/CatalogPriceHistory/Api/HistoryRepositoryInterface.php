<?php


namespace Evalent\CatalogPriceHistory\Api;


interface HistoryRepositoryInterface
{
    /**
     * @param \Magento\Catalog\Model\Product $product
     * @param $storeId
     * @param $websiteId
     * @param $customerGroupId
     * @return float|null
     */
    public function getLowestPrice($product, $storeId, $websiteId, $customerGroupId);

    /**
     * @param $storeId
     * @return int|null
     */
    public function getLowestPriceDays($storeId);
}