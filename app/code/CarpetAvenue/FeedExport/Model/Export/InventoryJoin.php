<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_FeedExport
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\FeedExport\Model\Export;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\App\ResourceConnection;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Joins stock index + open reservations so "ca_is_salable" can be computed without per-row queries.
 * Columns land on the product as fe_* keys (see SalableDataProvider).
 */
class InventoryJoin
{
    private const RESERVATION_TABLE = 'inventory_reservation';

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly StockResolverInterface $stockResolver,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function apply(Collection $collection, int $storeId): bool
    {
        $connection = $this->resourceConnection->getConnection();
        if (!$storeId || !$connection->isTableExists($this->resourceConnection->getTableName(self::RESERVATION_TABLE))) {
            return false;
        }

        $websiteCode = $this->storeManager->getWebsite($this->storeManager->getStore($storeId)->getWebsiteId())
            ->getCode();
        $stockId = (int)$this->stockResolver
            ->execute(SalesChannelInterface::TYPE_WEBSITE, $websiteCode)
            ->getStockId();
        $stockIndexTable = $this->resourceConnection->getTableName('inventory_stock_' . $stockId);
        if (!$connection->isTableExists($stockIndexTable)) {
            return false;
        }

        $select = $collection->getSelect();
        $select->joinLeft(
            ['fe_csi' => $this->resourceConnection->getTableName('cataloginventory_stock_item')],
            'fe_csi.product_id = e.entity_id AND fe_csi.stock_id = 1',
            [
                'fe_min_qty' => 'fe_csi.min_qty',
                'fe_use_config_min_qty' => 'fe_csi.use_config_min_qty',
            ]
        );
        $select->joinLeft(
            ['fe_is' => $stockIndexTable],
            'fe_is.sku = e.sku',
            [
                'fe_stock_qty' => 'fe_is.quantity',
                'fe_is_salable' => 'fe_is.is_salable',
            ]
        );
        // Open reservations per SKU on this stock (orders placed, not yet shipped/cancelled). Aggregated
        // inline: the old install relied on a hand-made DB view (vw_inventory_reservation) that new
        // databases do not have, which silently skipped every stock join.
        $reservations = $connection->select()
            ->from(
                $this->resourceConnection->getTableName(self::RESERVATION_TABLE),
                ['sku', 'quantity' => new \Zend_Db_Expr('SUM(quantity)')]
            )
            ->where('stock_id = ?', $stockId)
            ->group('sku');
        $select->joinLeft(
            ['fe_res' => $reservations],
            'fe_res.sku = e.sku',
            ['fe_reserved_qty' => 'fe_res.quantity']
        );
        return true;
    }
}
