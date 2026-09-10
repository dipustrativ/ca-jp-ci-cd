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

namespace CarpetAvenue\ExportImport\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Reads the per-order side tables the sales models expose poorly or not at all:
 *
 *  - `sales_order_tax_item`               per-item tax rows (joined to `sales_order_tax`
 *                                         so each row carries its rate code/title)
 *  - `sales_order_status_change_history`  the status-change log written by
 *                                         Magento\Sales\Observer\StoreStatusChangeObserver
 *                                         (distinct from `sales_order_status_history`,
 *                                         which is the comment/history the admin sees)
 *  - allocated sources                    MSI source(s) the order was shipped from,
 *                                         same derivation the sales order grid uses
 *                                         (`sales_shipment` → `inventory_shipment_source`
 *                                         → `inventory_source`)
 *
 * Read-only, plain SQL — nothing here writes.
 */
class OrderExtraData
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    /**
     * Per-item tax rows for one order, keyed by `sales_order_item.item_id`.
     *
     * Rows whose `item_id` is NULL (shipping tax, for example) are collected under
     * the '' key so callers can still export them at order level.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getTaxItems(int $orderId): array
    {
        $connection = $this->resourceConnection->getConnection('sales');
        $select = $connection->select()
            ->from(
                ['tax_item' => $this->resourceConnection->getTableName('sales_order_tax_item', 'sales')],
                [
                    'item_id',
                    'associated_item_id',
                    'taxable_item_type',
                    'tax_percent',
                    'amount',
                    'base_amount',
                    'real_amount',
                    'real_base_amount',
                ]
            )
            ->joinInner(
                ['tax' => $this->resourceConnection->getTableName('sales_order_tax', 'sales')],
                'tax.tax_id = tax_item.tax_id',
                ['code', 'title', 'priority', 'position']
            )
            ->where('tax.order_id = ?', $orderId)
            ->order('tax_item.tax_item_id ASC');

        $grouped = [];
        foreach ($connection->fetchAll($select) as $row) {
            $key = $row['item_id'] === null ? '' : (string)(int)$row['item_id'];
            $grouped[$key][] = [
                'code' => (string)$row['code'],
                'title' => (string)$row['title'],
                'taxable_item_type' => (string)$row['taxable_item_type'],
                'tax_percent' => (float)$row['tax_percent'],
                'amount' => (float)$row['amount'],
                'base_amount' => (float)$row['base_amount'],
                'real_amount' => (float)$row['real_amount'],
                'real_base_amount' => (float)$row['real_base_amount'],
                'associated_item_id' => $row['associated_item_id'] === null
                    ? null
                    : (int)$row['associated_item_id'],
            ];
        }

        return $grouped;
    }

    /**
     * The status-change log for one order, oldest first.
     *
     * @return array<int, array{status: string, created_at: string}>
     */
    public function getStatusChangeHistory(int $orderId): array
    {
        $connection = $this->resourceConnection->getConnection('sales');
        $table = $this->resourceConnection->getTableName('sales_order_status_change_history', 'sales');
        if (!$connection->isTableExists($table)) {
            return [];
        }

        $select = $connection->select()
            ->from($table, ['status', 'created_at'])
            ->where('order_id = ?', $orderId)
            ->order('entity_id ASC');

        $entries = [];
        foreach ($connection->fetchAll($select) as $row) {
            $entries[] = [
                'status' => (string)$row['status'],
                'created_at' => (string)$row['created_at'],
            ];
        }

        return $entries;
    }

    /**
     * MSI sources the order's shipments were fulfilled from.
     *
     * Mirrors Magento\InventoryShippingAdminUi\Model\ResourceModel\GetAllocatedSourcesForOrder
     * (the sales order grid's "Allocated Sources" column) without taking a hard
     * dependency on that adminhtml module, and returns the codes alongside the
     * names so the CSV stays machine-readable. Empty when MSI is absent or the
     * order has no shipment yet.
     *
     * @return array{codes: string[], names: string[]}
     */
    public function getAllocatedSources(int $orderId): array
    {
        $empty = ['codes' => [], 'names' => []];

        $salesConnection = $this->resourceConnection->getConnection('sales');
        $shipmentIds = $salesConnection->fetchCol(
            $salesConnection->select()
                ->from($this->resourceConnection->getTableName('sales_shipment', 'sales'), ['entity_id'])
                ->where('order_id = ?', $orderId)
        );
        if (empty($shipmentIds)) {
            return $empty;
        }

        // The inventory tables live on the default connection, which is not
        // necessarily the sales one (split-database setups).
        $connection = $this->resourceConnection->getConnection();
        $shipmentSourceTable = $this->resourceConnection->getTableName('inventory_shipment_source');
        $sourceTable = $this->resourceConnection->getTableName('inventory_source');
        if (!$connection->isTableExists($shipmentSourceTable) || !$connection->isTableExists($sourceTable)) {
            return $empty;
        }

        $select = $connection->select()
            ->from(
                ['shipment_source' => $shipmentSourceTable],
                ['source_code']
            )
            ->joinInner(
                ['source' => $sourceTable],
                'source.source_code = shipment_source.source_code',
                ['name']
            )
            ->where('shipment_source.shipment_id IN (?)', $shipmentIds)
            ->group('shipment_source.source_code');

        $codes = [];
        $names = [];
        foreach ($connection->fetchAll($select) as $row) {
            $codes[] = (string)$row['source_code'];
            $names[] = (string)$row['name'];
        }

        return ['codes' => $codes, 'names' => $names];
    }
}
