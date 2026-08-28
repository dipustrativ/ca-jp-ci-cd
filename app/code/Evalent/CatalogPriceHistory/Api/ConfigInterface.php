<?php


namespace Evalent\CatalogPriceHistory\Api;


interface ConfigInterface
{
    /**
     * @param $storeId
     * @return bool
     */
    public function isFrontendEnabled($storeId): bool;

    /**
     * @param $storeId
     * @return int
     */
    public function getLowestPriceDays($storeId): int;

    /**
     * @return bool
     */
    public function isCronEnabled(): bool;

    /**
     * @return array
     */
    public function getIgnoreForSkus(): array;
}