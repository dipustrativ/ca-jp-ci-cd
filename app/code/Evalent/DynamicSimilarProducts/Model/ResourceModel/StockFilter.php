<?php

declare(strict_types=1);

namespace Evalent\DynamicSimilarProducts\Model\ResourceModel;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\DB\Select;
use Magento\InventoryIndexer\Indexer\IndexStructure;
use Magento\InventoryIndexer\Model\StockIndexTableNameResolverInterface;

/**
 * Generic in-stock status filter for multi stocks inventory
 */
class StockFilter
{
    /**
     * @var StockIndexTableNameResolverInterface
     */
    private $stockIndexTableProvider;

    /**
     * @param StockIndexTableNameResolverInterface $stockIndexTableProvider
     */
    public function __construct(
        StockIndexTableNameResolverInterface $stockIndexTableProvider
    ) {
        $this->stockIndexTableProvider = $stockIndexTableProvider;
    }

    /**
     * Add in-stock status constraint to the select for non default stock
     *
     * @param Collection $collection
     * @param string $productTableAlias
     * @param string $reservationTableAlias
     * @param string $stockTableAlias
     * @param int $stockId
     * @return Select
     */
    public function execute(
        Collection $collection,
        string     $productTableAlias,
        string     $reservationTableAlias,
        string     $stockTableAlias,
        int        $stockId
    ): Select {
        $select = $collection->getSelect();
        $stockStatusTable = $this->stockIndexTableProvider->execute($stockId);
        $reservationTable = $collection->getResource()->getTable('inventory_reservation');
        $quantityFieldName = IndexStructure::QUANTITY;
        $select->joinLeft(
            [$reservationTableAlias => new \Zend_Db_Expr("(Select sku, SUM(quantity) as quantity from $reservationTable GROUP BY sku)")],
            "{$reservationTableAlias}.sku = {$productTableAlias}.sku",
            ['reservation_qty' => "{$reservationTableAlias}.{$quantityFieldName}"]
        );
        $select->joinLeft(
            [$stockTableAlias => $stockStatusTable],
            "{$stockTableAlias}.sku = {$productTableAlias}.sku",
            ['stock_quantity' => $quantityFieldName]
        );
        $select->where("IFNULL({$stockTableAlias}.{$quantityFieldName}, 0) + IFNULL({$reservationTableAlias}.{$quantityFieldName}, 0) > 0");

        return $select;
    }
}
