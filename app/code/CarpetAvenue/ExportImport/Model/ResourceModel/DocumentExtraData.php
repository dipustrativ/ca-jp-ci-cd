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
 * The `sales_order` lookups the invoice / credit-memo export and import need.
 *
 * A document row carries `order_id`, but the CSV addresses the order by its
 * increment id so a file can move between instances. Translating between the two
 * is the whole job here. Doing it in SQL keeps the exporter from loading an order
 * model per document (an export of 5 000 invoices would otherwise load 5 000
 * orders), and results are cached for the life of the request.
 *
 * Read-only, plain SQL — nothing here writes.
 */
class DocumentExtraData
{
    /** @var array<int, string> order entity id => increment id */
    private array $incrementIdCache = [];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    /**
     * One order's increment id, '' when the order is gone.
     */
    public function getOrderIncrementId(int $orderId): string
    {
        if ($orderId <= 0) {
            return '';
        }
        if (!array_key_exists($orderId, $this->incrementIdCache)) {
            $connection = $this->resourceConnection->getConnection('sales');
            $this->incrementIdCache[$orderId] = (string)$connection->fetchOne(
                $connection->select()
                    ->from($this->resourceConnection->getTableName('sales_order', 'sales'), ['increment_id'])
                    ->where('entity_id = ?', $orderId)
            );
        }
        return $this->incrementIdCache[$orderId];
    }

    /**
     * Resolve order increment ids to entity ids in one query.
     *
     * Used by the validator (to reject a document whose order is absent) and by
     * the exporter's `order_increment_id` filter.
     *
     * @param string[] $incrementIds
     * @return array<string, int> increment id => entity id, missing ones absent
     */
    public function resolveOrderIds(array $incrementIds): array
    {
        $incrementIds = array_values(array_unique(array_filter($incrementIds)));
        if ($incrementIds === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection('sales');
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from(
                    $this->resourceConnection->getTableName('sales_order', 'sales'),
                    ['increment_id', 'entity_id']
                )
                ->where('increment_id IN (?)', $incrementIds)
        );

        $resolved = [];
        foreach ($rows as $incrementId => $entityId) {
            $resolved[(string)$incrementId] = (int)$entityId;
            $this->incrementIdCache[(int)$entityId] = (string)$incrementId;
        }
        return $resolved;
    }

    /**
     * The order items of a set of orders, keyed for SKU lookup.
     *
     * A document item points at an order item (`order_item_id`), which is
     * instance-local, so the CSV carries the SKU and the link is re-resolved
     * here. Both the validator (to warn about a SKU the order does not have) and
     * the importer (to set `order_item_id`) need exactly this map.
     *
     * A SKU appearing on two lines of the same order keeps the LAST one — the
     * same ambiguity the order importer has, and unresolvable from a SKU alone.
     *
     * @param int[] $orderIds
     * @return array<int, array<string, int>> order id => sku => order item id
     */
    public function getOrderItemSkus(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if ($orderIds === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection('sales');
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('sales_order_item', 'sales'),
                ['order_id', 'sku', 'item_id']
            )
            ->where('order_id IN (?)', $orderIds)
            ->order('item_id ASC');

        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $sku = (string)$row['sku'];
            if ($sku !== '') {
                $map[(int)$row['order_id']][$sku] = (int)$row['item_id'];
            }
        }
        return $map;
    }
}
