<?php
declare(strict_types=1);

namespace Evalent\CatalogPriceHistory\Model\ResourceModel\Product\Indexer\Price;

interface HistoryInterface
{
    /**
     * Reindex temporary (price result data) for all products
     *
     * @return $this
     */
    public function reindexAll(): HistoryInterface;

    /**
     * Reindex temporary (price result data) for defined product(s)
     *
     * @param int|array $entityIds
     * @return $this
     */
    public function reindexEntity($entityIds): HistoryInterface;
}
