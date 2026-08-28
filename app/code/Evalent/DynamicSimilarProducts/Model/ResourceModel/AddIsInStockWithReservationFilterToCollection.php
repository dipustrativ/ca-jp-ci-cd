<?php

declare(strict_types=1);

namespace Evalent\DynamicSimilarProducts\Model\ResourceModel;

use Magento\Catalog\Model\ResourceModel\Product\Collection;

/**
 * Adapt adding is in stock filter to collection for Multi Stocks.
 */
class AddIsInStockWithReservationFilterToCollection
{
    /**
     * @var StockFilter
     */
    private $stockStatusFilter;

    /**
     * @param StockFilter $stockStatusFilter
     */
    public function __construct(
        StockFilter $stockStatusFilter
    ) {
        $this->stockStatusFilter = $stockStatusFilter;
    }

    /**
     * Modify "is in stock" collection filter to support non-default stocks.
     *
     * @param Collection $collection
     * @param int $stockId
     * @return void
     */
    public function execute(Collection $collection, int $stockId)
    {
        $this->stockStatusFilter->execute(
            $collection,
            'e',
            'reservation_qty',
            'stock_qty',
            $stockId
        );
    }
}
