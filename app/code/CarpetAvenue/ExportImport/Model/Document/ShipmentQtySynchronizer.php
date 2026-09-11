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
use Magento\Sales\Model\Order;

/**
 * Rebuilds an order's per-item `qty_shipped` from the shipments that exist for it
 * — the shipment counterpart of {@see OrderTotalsSynchronizer}.
 *
 * ## Why this exists
 *
 * Magento maintains `sales_order_item.qty_shipped` incrementally:
 * `Shipment\Item::register()` ADDS the shipped quantity to the order item, and
 * `Order\Shipment::register()` is reachable only through
 * `ShipmentFactory::create()`, which recalculates from the order and refuses to
 * run once `canShip()` is false — which it is on an order the order CSV has just
 * imported. So the arithmetic is repeated here as a full recompute from the
 * shipment items currently in the database, which makes re-importing the same
 * file a no-op.
 *
 * ## What it does not do
 *
 * There is no order-level "total shipped" column to maintain — `qty_shipped` per
 * item is the whole of what a shipment feeds. `sales_shipment` has no state
 * column, so unlike the money documents there is no canceled document to
 * exclude: every shipment row of the order counts.
 *
 * The order's `state` and `status` are never changed, for the same reason as in
 * {@see OrderTotalsSynchronizer}: a migrated order's status came from its own
 * CSV. Importing a shipment does not move an order to `complete` here the way
 * shipping one in the admin would.
 *
 * Mutation only: the caller saves the order, which persists the items too via
 * `Magento\Sales\Model\ResourceModel\Order\Relation::processRelation()`.
 */
class ShipmentQtySynchronizer implements OrderAggregateSynchronizerInterface
{
    public function __construct(
        private readonly OrderDocumentTotals $documentTotals,
    ) {
    }

    /**
     * An order item with no shipment rows is reset to 0 rather than skipped —
     * the point of a recompute is that the order matches the shipments,
     * including where a shipment was deleted.
     */
    public function apply(Order $order): void
    {
        $orderId = (int)$order->getId();
        if ($orderId <= 0) {
            return;
        }

        $shippedByItem = $this->documentTotals->getShippedItemQtys($orderId);
        foreach ($order->getAllItems() as $item) {
            $item->setQtyShipped($shippedByItem[(int)$item->getItemId()] ?? 0.0);
        }
    }
}
