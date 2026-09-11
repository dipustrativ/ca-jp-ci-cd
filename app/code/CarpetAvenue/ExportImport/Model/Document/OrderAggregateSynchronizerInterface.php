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

use Magento\Sales\Model\Order;

/**
 * Rebuilds the order-level figures that a given document type feeds.
 *
 * An order carries running aggregates of its documents, and which ones depend on
 * the document: an invoice or credit memo feeds the `*_invoiced` / `*_refunded` /
 * `total_paid` family ({@see OrderTotalsSynchronizer}), a shipment feeds the
 * per-item `qty_shipped` ({@see ShipmentQtySynchronizer}). One implementation is
 * injected per document type in di.xml, so importing shipments never rewrites an
 * invoiced total and importing invoices never rewrites a shipped quantity.
 *
 * Every implementation is a FULL RECOMPUTE from the documents currently in the
 * database, not an increment — which is what makes re-importing the same file a
 * no-op instead of doubling every figure.
 *
 * Implementations mutate the order (and its items) only; the caller saves it.
 */
interface OrderAggregateSynchronizerInterface
{
    public function apply(Order $order): void;
}
