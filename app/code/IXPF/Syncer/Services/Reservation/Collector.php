<?php

declare(strict_types=1);

namespace IXPF\Syncer\Services\Reservation;

use Magento\InventorySourceSelectionApi\Api\Data\ItemRequestInterfaceFactory;
use Magento\InventorySourceSelectionApi\Api\GetDefaultSourceSelectionAlgorithmCodeInterface;
use Magento\InventorySourceSelectionApi\Api\SourceSelectionServiceInterface;
use Magento\InventorySourceSelectionApi\Model\GetInventoryRequestFromOrder;
use Magento\Sales\Model\Order;

/**
 * Work out which warehouse each ordered line will come from.
 *
 * MSI does not decide this until a shipment exists — inventory_reservation holds
 * only a stock_id, no source_code — so at placement time we have to ask the
 * Source Selection Algorithm ourselves.
 *
 * Adapted from Services\Order\CreateShipment::resolveAutoSource(), with one
 * deliberate difference: that method throws when a line spans several sources,
 * because a Magento shipment can only deduct from one. Here the split is the
 * answer, so it is kept and emitted as one entry per (sku, source).
 */
class Collector
{
    public function __construct(
        private readonly GetInventoryRequestFromOrder $getInventoryRequestFromOrder,
        private readonly ItemRequestInterfaceFactory $itemRequestFactory,
        private readonly SourceSelectionServiceInterface $sourceSelectionService,
        private readonly GetDefaultSourceSelectionAlgorithmCodeInterface $getDefaultAlgorithmCode
    ) {
    }

    /**
     * Resolve the order's lines to (sku, qty, source) triples.
     *
     * Quantities come back positive — the caller decides the sign.
     *
     * @return array<int, array{sku: string, qty: float, source: string}>
     * @throws \Exception When no combination of sources can cover the order.
     */
    public function collect(Order $order): array
    {
        $requestItems = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $qty = (float)$item->getQtyOrdered();
            if ($qty <= 0) {
                continue;
            }
            $requestItems[] = $this->itemRequestFactory->create([
                'sku' => $item->getSku(),
                'qty' => $qty,
            ]);
        }

        if (!$requestItems) {
            throw new \Exception("No reservable items on order #{$order->getIncrementId()}");
        }

        $inventoryRequest = $this->getInventoryRequestFromOrder->execute(
            (int)$order->getEntityId(),
            $requestItems
        );

        $result = $this->sourceSelectionService->execute(
            $inventoryRequest,
            $this->getDefaultAlgorithmCode->execute()
        );

        if (!$result->isShippable()) {
            throw new \Exception(
                "No inventory source has enough stock to cover order #{$order->getIncrementId()}"
            );
        }

        $lines = [];
        foreach ($result->getSourceSelectionItems() as $ssItem) {
            $qtyToDeduct = (float)$ssItem->getQtyToDeduct();
            if ($qtyToDeduct <= 0) {
                continue;
            }
            $lines[] = [
                'sku' => (string)$ssItem->getSku(),
                'qty' => $qtyToDeduct,
                'source' => (string)$ssItem->getSourceCode(),
            ];
        }

        return $lines;
    }
}
