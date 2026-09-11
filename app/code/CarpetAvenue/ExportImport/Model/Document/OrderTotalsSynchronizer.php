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

namespace CarpetAvenue\ExportImport\Model\Document;

use CarpetAvenue\ExportImport\Model\ResourceModel\OrderDocumentTotals;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;

/**
 * Rebuilds an order's invoiced / refunded / paid figures from the invoices and
 * credit memos that exist for it.
 *
 * ## Why this exists
 *
 * An order carries running aggregates of its documents — `total_invoiced`,
 * `total_paid`, `total_refunded`, the per-item `qty_invoiced` / `qty_refunded`,
 * and a dozen more. Magento maintains them incrementally: `Invoice::register()`
 * ADDS the new invoice's amounts to the order,
 * `Creditmemo\RefundOperation::execute()` adds the credit memo's. Neither is
 * usable here — both drag in the payment method (`register()` reaches
 * `$order->getPayment()->getMethodInstance()` and can capture, `pay()` calls
 * `$payment->pay()`), and a migration import must never touch a gateway.
 *
 * So the arithmetic is repeated here, with one deliberate change: this is a FULL
 * RECOMPUTE, not an increment. Every figure is derived from the complete set of
 * documents currently in the database, which makes re-importing the same file a
 * no-op instead of doubling every total.
 *
 * ## What it does not do
 *
 * `total_due` / `base_total_due` are absent on purpose: `Order::getData()`
 * intercepts both keys and returns `grand_total - total_paid` computed on the
 * fly, so whatever is written to those columns is ignored on read.
 *
 * The order's `state` and `status` are never changed. A migrated order's status
 * came from its own CSV and is not this class's business — importing an invoice
 * does not move an order to `processing` here the way placing one in the admin
 * would.
 *
 * `qty_shipped` is not here either: it is fed by shipments, not by the money
 * documents, and gets the same treatment in {@see ShipmentQtySynchronizer}.
 * Keeping the two apart is what stops an invoice import from zeroing the shipped
 * quantities of an install whose shipments were never imported.
 *
 * Mutation only: the caller saves the order, which persists the items too via
 * `Magento\Sales\Model\ResourceModel\Order\Relation::processRelation()`.
 */
class OrderTotalsSynchronizer implements OrderAggregateSynchronizerInterface
{
    /** Both document types number the canceled state 3. */
    private const STATE_CANCELED = Invoice::STATE_CANCELED;

    public function __construct(
        private readonly OrderDocumentTotals $documentTotals,
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    /**
     * Recompute every document-derived figure on the order and its items.
     *
     * The order itself is only mutated — the caller saves it. The sibling
     * invoices' `base_total_refunded` is written here directly; see
     * {@see syncInvoiceRefundedAmounts()}.
     */
    public function apply(Order $order): void
    {
        $orderId = (int)$order->getId();
        if ($orderId <= 0) {
            return;
        }

        $this->applyOrderTotals($order, $orderId);
        $this->applyItemTotals($order, $orderId);
        $this->syncInvoiceRefundedAmounts($orderId);
    }

    private function applyOrderTotals(Order $order, int $orderId): void
    {
        $invoiced = $this->documentTotals->getInvoicedTotals($orderId);
        $paid = $this->documentTotals->getPaidTotals($orderId);
        $refunded = $this->documentTotals->getRefundedTotals($orderId);
        $split = $this->documentTotals->getRefundSplit($orderId);

        // --- invoiced (mirrors Invoice::register()) --------------------------
        $order->setTotalInvoiced($invoiced['grand_total']);
        $order->setBaseTotalInvoiced($invoiced['base_grand_total']);
        $order->setSubtotalInvoiced($invoiced['subtotal']);
        $order->setBaseSubtotalInvoiced($invoiced['base_subtotal']);
        $order->setTaxInvoiced($invoiced['tax_amount']);
        $order->setBaseTaxInvoiced($invoiced['base_tax_amount']);
        $order->setShippingInvoiced($invoiced['shipping_amount']);
        $order->setBaseShippingInvoiced($invoiced['base_shipping_amount']);
        $order->setDiscountInvoiced($invoiced['discount_amount']);
        $order->setBaseDiscountInvoiced($invoiced['base_discount_amount']);
        $order->setDiscountTaxCompensationInvoiced($invoiced['discount_tax_compensation_amount']);
        $order->setBaseDiscountTaxCompensationInvoiced($invoiced['base_discount_tax_compensation_amount']);

        // --- paid (mirrors Invoice::pay()) ----------------------------------
        $order->setTotalPaid($paid['grand_total']);
        $order->setBaseTotalPaid($paid['base_grand_total']);

        // --- refunded (mirrors Creditmemo\RefundOperation::execute()) --------
        $order->setTotalRefunded($refunded['grand_total']);
        $order->setBaseTotalRefunded($refunded['base_grand_total']);
        $order->setSubtotalRefunded($refunded['subtotal']);
        $order->setBaseSubtotalRefunded($refunded['base_subtotal']);
        $order->setTaxRefunded($refunded['tax_amount']);
        $order->setBaseTaxRefunded($refunded['base_tax_amount']);
        $order->setShippingRefunded($refunded['shipping_amount']);
        $order->setBaseShippingRefunded($refunded['base_shipping_amount']);
        $order->setShippingTaxRefunded($refunded['shipping_tax_amount']);
        $order->setBaseShippingTaxRefunded($refunded['base_shipping_tax_amount']);
        $order->setDiscountRefunded($refunded['discount_amount']);
        $order->setBaseDiscountRefunded($refunded['base_discount_amount']);
        $order->setDiscountTaxCompensationRefunded($refunded['discount_tax_compensation_amount']);
        $order->setBaseDiscountTaxCompensationRefunded($refunded['base_discount_tax_compensation_amount']);

        // The order's adjustment_* columns are the sum of its credit memos'
        // adjustments (RefundOperation adds each memo's into them), so they are
        // recomputed here too rather than left at whatever the order CSV carried.
        $order->setAdjustmentPositive($refunded['adjustment_positive']);
        $order->setBaseAdjustmentPositive($refunded['base_adjustment_positive']);
        $order->setAdjustmentNegative($refunded['adjustment_negative']);
        $order->setBaseAdjustmentNegative($refunded['base_adjustment_negative']);

        $order->setTotalOnlineRefunded($split['online']['grand_total']);
        $order->setBaseTotalOnlineRefunded($split['online']['base_grand_total']);
        $order->setTotalOfflineRefunded($split['offline']['grand_total']);
        $order->setBaseTotalOfflineRefunded($split['offline']['base_grand_total']);
    }

    /**
     * Per-item invoiced / refunded figures.
     *
     * An order item with no document rows is reset to 0 rather than skipped —
     * the whole point of a recompute is that the order matches the documents,
     * including where a document was deleted.
     */
    private function applyItemTotals(Order $order, int $orderId): void
    {
        $invoicedByItem = $this->documentTotals->getInvoicedItemTotals($orderId);
        $refundedByItem = $this->documentTotals->getRefundedItemTotals($orderId);
        $zero = array_fill_keys(
            [
                'qty',
                'row_total',
                'base_row_total',
                'tax_amount',
                'base_tax_amount',
                'discount_amount',
                'base_discount_amount',
                'discount_tax_compensation_amount',
                'base_discount_tax_compensation_amount',
            ],
            0.0
        );

        foreach ($order->getAllItems() as $item) {
            $itemId = (int)$item->getItemId();
            $invoiced = $invoicedByItem[$itemId] ?? $zero;
            $refunded = $refundedByItem[$itemId] ?? $zero;

            // Mirrors Invoice\Item::register().
            $item->setQtyInvoiced($invoiced['qty']);
            $item->setRowInvoiced($invoiced['row_total']);
            $item->setBaseRowInvoiced($invoiced['base_row_total']);
            $item->setTaxInvoiced($invoiced['tax_amount']);
            $item->setBaseTaxInvoiced($invoiced['base_tax_amount']);
            $item->setDiscountInvoiced($invoiced['discount_amount']);
            $item->setBaseDiscountInvoiced($invoiced['base_discount_amount']);
            $item->setDiscountTaxCompensationInvoiced($invoiced['discount_tax_compensation_amount']);
            $item->setBaseDiscountTaxCompensationInvoiced($invoiced['base_discount_tax_compensation_amount']);

            // Mirrors Creditmemo\Item::register(). `amount_refunded` is the
            // row total, not the qty — Magento's naming, kept as-is.
            $item->setQtyRefunded($refunded['qty']);
            $item->setAmountRefunded($refunded['row_total']);
            $item->setBaseAmountRefunded($refunded['base_row_total']);
            $item->setTaxRefunded($refunded['tax_amount']);
            $item->setBaseTaxRefunded($refunded['base_tax_amount']);
            $item->setDiscountRefunded($refunded['discount_amount']);
            $item->setBaseDiscountRefunded($refunded['base_discount_amount']);
            $item->setDiscountTaxCompensationRefunded($refunded['discount_tax_compensation_amount']);
            $item->setBaseDiscountTaxCompensationRefunded($refunded['base_discount_tax_compensation_amount']);
        }
    }

    /**
     * Rewrite `sales_invoice.base_total_refunded` for every invoice of the order.
     *
     * This is the one figure that lives on a *sibling* document rather than on
     * the order: Magento keeps it up to date in
     * `Creditmemo\Relation\Refund::processRelation()`, which the import bypasses
     * for the reasons in the class docblock. It gates the admin's "Credit Memo"
     * button on an invoice ({@see \Magento\Sales\Model\Order\Invoice::canRefund()}),
     * so a stale zero would offer a refund on an already fully-credited invoice.
     *
     * One statement rather than a loop over invoice models, because the value is
     * a pure aggregate of another table and an invoice with no credit memos must
     * be reset to 0 — which a loop over the credit memos that DO exist would miss.
     * Written directly instead of through the repository: the amount is derived,
     * not authored, and re-saving every invoice would fire the invoice save
     * events (and the grid sync) for a column no grid shows.
     */
    private function syncInvoiceRefundedAmounts(int $orderId): void
    {
        $connection = $this->resourceConnection->getConnection('sales');
        $invoiceTable = $this->resourceConnection->getTableName('sales_invoice', 'sales');
        $creditmemoTable = $this->resourceConnection->getTableName('sales_creditmemo', 'sales');

        $connection->query(
            sprintf(
                'UPDATE %1$s AS invoice SET invoice.base_total_refunded = ('
                . ' SELECT COALESCE(SUM(creditmemo.base_grand_total), 0) FROM %2$s AS creditmemo'
                . ' WHERE creditmemo.invoice_id = invoice.entity_id'
                . ' AND (creditmemo.state IS NULL OR creditmemo.state <> ?)'
                . ') WHERE invoice.order_id = ?',
                $connection->quoteIdentifier($invoiceTable),
                $connection->quoteIdentifier($creditmemoTable)
            ),
            [self::STATE_CANCELED, $orderId]
        );
    }
}
