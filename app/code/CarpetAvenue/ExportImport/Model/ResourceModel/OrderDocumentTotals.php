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
use Magento\Sales\Model\Order\Invoice;

/**
 * Sums one order's invoices, credit memos and shipments, so
 * {@see \CarpetAvenue\ExportImport\Model\Document\OrderTotalsSynchronizer} can
 * rebuild the order's `*_invoiced` / `*_refunded` / `total_paid` figures — and
 * {@see \CarpetAvenue\ExportImport\Model\Document\ShipmentQtySynchronizer} the
 * per-item `qty_shipped` — from the documents that actually exist.
 *
 * Why SQL rather than the sales models: the synchroniser recomputes totals from
 * scratch on every run so that re-importing the same file is idempotent, and a
 * full recompute means reading every document and every document item of the
 * order. Six grouped queries do that; loading the model graph would be hundreds.
 *
 * Canceled documents are excluded throughout (`state = 3` on both money-document
 * tables). Magento's own `Invoice::cancel()` subtracts a canceled invoice back
 * out of the order aggregates, so a canceled document must contribute nothing
 * here either. `sales_shipment` has no state column, so nothing is excluded
 * there — see {@see getShippedItemQtys()}.
 *
 * Read-only, plain SQL — nothing here writes.
 */
class OrderDocumentTotals
{
    /**
     * The canceled state. Both document types number it 3
     * ({@see Invoice::STATE_CANCELED}, and Creditmemo::STATE_CANCELED), so one
     * constant covers both tables — named after neither, to make clear the queries
     * below rely on the two agreeing rather than on one of them in particular.
     */
    private const STATE_CANCELED = Invoice::STATE_CANCELED;

    /** Document-level columns summed for the `*_invoiced` family. */
    private const INVOICE_SUM_COLUMNS = [
        'grand_total',
        'base_grand_total',
        'subtotal',
        'base_subtotal',
        'tax_amount',
        'base_tax_amount',
        'shipping_amount',
        'base_shipping_amount',
        'discount_amount',
        'base_discount_amount',
        'discount_tax_compensation_amount',
        'base_discount_tax_compensation_amount',
    ];

    /** Document-level columns summed for the `*_refunded` family. */
    private const CREDITMEMO_SUM_COLUMNS = [
        'grand_total',
        'base_grand_total',
        'subtotal',
        'base_subtotal',
        'tax_amount',
        'base_tax_amount',
        'shipping_amount',
        'base_shipping_amount',
        'shipping_tax_amount',
        'base_shipping_tax_amount',
        'discount_amount',
        'base_discount_amount',
        'discount_tax_compensation_amount',
        'base_discount_tax_compensation_amount',
        'adjustment_positive',
        'base_adjustment_positive',
        'adjustment_negative',
        'base_adjustment_negative',
    ];

    /** Item-level columns summed per order item. Identical for both item tables. */
    private const ITEM_SUM_COLUMNS = [
        'qty',
        'row_total',
        'base_row_total',
        'tax_amount',
        'base_tax_amount',
        'discount_amount',
        'base_discount_amount',
        'discount_tax_compensation_amount',
        'base_discount_tax_compensation_amount',
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    /**
     * Totals of every non-canceled invoice of the order.
     *
     * @return array<string, float>
     */
    public function getInvoicedTotals(int $orderId): array
    {
        return $this->sumDocuments('sales_invoice', $orderId, self::INVOICE_SUM_COLUMNS);
    }

    /**
     * Totals of the invoices in state PAID — the order's `total_paid`.
     *
     * @return array<string, float>
     */
    public function getPaidTotals(int $orderId): array
    {
        return $this->sumDocuments(
            'sales_invoice',
            $orderId,
            ['grand_total', 'base_grand_total'],
            Invoice::STATE_PAID
        );
    }

    /**
     * Totals of every non-canceled credit memo of the order.
     *
     * @return array<string, float>
     */
    public function getRefundedTotals(int $orderId): array
    {
        return $this->sumDocuments('sales_creditmemo', $orderId, self::CREDITMEMO_SUM_COLUMNS);
    }

    /**
     * Credit-memo grand totals split into the order's online / offline buckets.
     *
     * There is no column that records which bucket a refund went into — Magento
     * decides it at refund time from whether the payment was refunded through the
     * gateway, and keeps only the two running order totals. A credit memo with a
     * `transaction_id` was settled through a gateway, so that is the split used
     * here. The split can therefore be wrong for a gateway that does not write a
     * transaction id; the SUM of the two buckets is exact either way, and that is
     * the figure `total_refunded` and the admin Refunded column show.
     *
     * @return array{online: array<string, float>, offline: array<string, float>}
     */
    public function getRefundSplit(int $orderId): array
    {
        $columns = ['grand_total', 'base_grand_total'];
        return [
            'online' => $this->sumDocuments(
                'sales_creditmemo',
                $orderId,
                $columns,
                null,
                "transaction_id IS NOT NULL AND transaction_id <> ''"
            ),
            'offline' => $this->sumDocuments(
                'sales_creditmemo',
                $orderId,
                $columns,
                null,
                "transaction_id IS NULL OR transaction_id = ''"
            ),
        ];
    }

    /**
     * Per-order-item invoiced totals, keyed by `sales_order_item.item_id`.
     *
     * @return array<int, array<string, float>>
     */
    public function getInvoicedItemTotals(int $orderId): array
    {
        return $this->sumItems('sales_invoice', 'sales_invoice_item', $orderId);
    }

    /**
     * Per-order-item refunded totals, keyed by `sales_order_item.item_id`.
     *
     * @return array<int, array<string, float>>
     */
    public function getRefundedItemTotals(int $orderId): array
    {
        return $this->sumItems('sales_creditmemo', 'sales_creditmemo_item', $orderId);
    }

    /**
     * Shipped quantity per order item, keyed by `sales_order_item.item_id`.
     *
     * The shipment counterpart of {@see getInvoicedItemTotals()}, and a single
     * column rather than the nine of the money documents: a shipment moves
     * goods, so `qty` is the only figure it feeds back to the order item.
     *
     * No state filter — `sales_shipment` has no state column, so every shipment
     * of the order counts.
     *
     * @return array<int, float> order item id => shipped qty
     */
    public function getShippedItemQtys(int $orderId): array
    {
        $connection = $this->resourceConnection->getConnection('sales');
        $select = $connection->select()
            ->from(
                ['item' => $this->resourceConnection->getTableName('sales_shipment_item', 'sales')],
                [
                    'order_item_id' => 'item.order_item_id',
                    'qty' => new \Zend_Db_Expr('SUM(item.qty)'),
                ]
            )
            ->joinInner(
                ['document' => $this->resourceConnection->getTableName('sales_shipment', 'sales')],
                'document.entity_id = item.parent_id',
                []
            )
            ->where('document.order_id = ?', $orderId)
            ->where('item.order_item_id IS NOT NULL')
            ->group('item.order_item_id');

        $qtys = [];
        foreach ($connection->fetchAll($select) as $row) {
            $qtys[(int)$row['order_item_id']] = (float)$row['qty'];
        }
        return $qtys;
    }

    /**
     * SUM() one document table's columns for one order.
     *
     * @param string[] $columns
     * @return array<string, float> column => sum (0.0 when there are no rows)
     */
    private function sumDocuments(
        string $table,
        int $orderId,
        array $columns,
        ?int $state = null,
        ?string $extraWhere = null
    ): array {
        $connection = $this->resourceConnection->getConnection('sales');
        $expressions = [];
        foreach ($columns as $column) {
            $expressions[$column] = new \Zend_Db_Expr(sprintf('SUM(%s)', $connection->quoteIdentifier($column)));
        }

        $select = $connection->select()
            ->from($this->resourceConnection->getTableName($table, 'sales'), $expressions)
            ->where('order_id = ?', $orderId);

        if ($state !== null) {
            $select->where('state = ?', $state);
        } else {
            $select->where('state IS NULL OR state <> ?', self::STATE_CANCELED);
        }
        if ($extraWhere !== null) {
            $select->where($extraWhere);
        }

        $row = $connection->fetchRow($select) ?: [];
        $sums = [];
        foreach ($columns as $column) {
            $sums[$column] = (float)($row[$column] ?? 0);
        }
        return $sums;
    }

    /**
     * SUM() one document item table's columns per order item, for one order.
     *
     * @return array<int, array<string, float>>
     */
    private function sumItems(string $documentTable, string $itemTable, int $orderId): array
    {
        $connection = $this->resourceConnection->getConnection('sales');
        $expressions = ['order_item_id' => 'item.order_item_id'];
        foreach (self::ITEM_SUM_COLUMNS as $column) {
            $expressions[$column] = new \Zend_Db_Expr(
                sprintf('SUM(item.%s)', $connection->quoteIdentifier($column))
            );
        }

        $select = $connection->select()
            ->from(['item' => $this->resourceConnection->getTableName($itemTable, 'sales')], $expressions)
            ->joinInner(
                ['document' => $this->resourceConnection->getTableName($documentTable, 'sales')],
                'document.entity_id = item.parent_id',
                []
            )
            ->where('document.order_id = ?', $orderId)
            ->where('document.state IS NULL OR document.state <> ?', self::STATE_CANCELED)
            ->where('item.order_item_id IS NOT NULL')
            ->group('item.order_item_id');

        $totals = [];
        foreach ($connection->fetchAll($select) as $row) {
            $itemTotals = [];
            foreach (self::ITEM_SUM_COLUMNS as $column) {
                $itemTotals[$column] = (float)$row[$column];
            }
            $totals[(int)$row['order_item_id']] = $itemTotals;
        }
        return $totals;
    }
}
