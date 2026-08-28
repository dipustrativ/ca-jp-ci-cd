<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Order;

use Exception;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\InventoryCatalogApi\Model\IsSingleSourceModeInterface;
use Magento\InventorySourceSelectionApi\Api\Data\ItemRequestInterfaceFactory;
use Magento\InventorySourceSelectionApi\Api\GetDefaultSourceSelectionAlgorithmCodeInterface;
use Magento\InventorySourceSelectionApi\Api\SourceSelectionServiceInterface;
use Magento\InventorySourceSelectionApi\Model\GetInventoryRequestFromOrder;
use Magento\Sales\Api\Data\ShipmentExtensionFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\ShipmentSender;
use Magento\Sales\Model\Order\ShipmentFactory;

class CreateShipment
{
    public function __construct(
        private readonly OrderRepositoryInterface  $orderRepository,
        private readonly ShipmentFactory           $shipmentFactory,
        private readonly ShipmentSender            $shipmentSender,
        private readonly TransactionFactory        $transactionFactory,
        private readonly ShipmentExtensionFactory  $shipmentExtensionFactory,
        private readonly IsSingleSourceModeInterface $isSingleSourceMode,
        private readonly SourceRepositoryInterface  $sourceRepository,
        private readonly GetInventoryRequestFromOrder $getInventoryRequestFromOrder,
        private readonly ItemRequestInterfaceFactory $itemRequestFactory,
        private readonly SourceSelectionServiceInterface $sourceSelectionService,
        private readonly GetDefaultSourceSelectionAlgorithmCodeInterface $getDefaultAlgorithmCode,
    ) {
    }

    /**
     * Create shipment for an order with custom tracks and comment.
     *
     * Payload:
     * {
     *   "items":   [{"order_item_id"|"sku": ..., "qty": n}],   // optional, full ship if omitted
     *   "tracks":  [{"track_number": "...", "title": "...", "carrier_code": "custom"}],
     *   "comment": "free text",
     *   "notify":  true|false,
     *   "visible_on_front": true|false
     * }
     *
     * @param int   $orderId
     * @param array $payload
     * @return array
     * @throws Exception
     */
    public function execute(int $orderId, array $payload): array
    {
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException) {
            throw new Exception("Order #$orderId not found");
        }

        if (!in_array($order->getState(), [Order::STATE_NEW, Order::STATE_PROCESSING], true)) {
            throw new Exception(
                "Order #{$order->getIncrementId()} can only be shipped in the 'new' or 'processing' state. "
                . "Current state: {$order->getState()}, status: {$order->getStatus()}"
            );
        }

        if (!$order->canShip()) {
            throw new Exception(
                "Order #{$order->getIncrementId()} cannot be shipped. "
                . "State: {$order->getState()}, status: {$order->getStatus()}"
            );
        }

        // Items are required — caller must specify which products + qty to ship.
        // Accepts either order_item_id or sku to identify items.
        if (empty($payload['items']) || !is_array($payload['items'])) {
            throw new Exception("'items' is required — specify products and qty to ship");
        }

        $qtys = [];
        $skuToItemId = $this->buildSkuMap($order);
        foreach ($payload['items'] as $item) {
            $orderItemId = null;
            if (!empty($item['order_item_id'])) {
                $orderItemId = (int) $item['order_item_id'];
            } elseif (!empty($item['sku'])) {
                $orderItemId = $skuToItemId[$item['sku']] ?? null;
                if (!$orderItemId) {
                    throw new Exception("SKU '{$item['sku']}' not found in order #{$order->getIncrementId()}");
                }
            } else {
                throw new Exception("Each item requires 'order_item_id' or 'sku'");
            }
            if (!isset($item['qty']) || (float) $item['qty'] <= 0) {
                throw new Exception("Each item requires a positive 'qty'");
            }
            $qtys[$orderItemId] = (float) $item['qty'];
        }

        // Build tracks from payload
        $tracks = $this->buildTracks($payload['tracks'] ?? []);

        $shipment = $this->shipmentFactory->create($order, $qtys, $tracks);

        if (!$shipment || !$shipment->getTotalQty()) {
            throw new Exception(
                "Cannot create shipment without items for order #{$order->getIncrementId()}"
            );
        }

        // MSI source — which source qty deducts from (mirrors Magento admin source selection)
        $this->assignSource($shipment, $payload['source_code'] ?? null, $order);

        // Comment
        $comment = trim((string) ($payload['comment'] ?? ''));
        $notify = !empty($payload['notify']);
        $visibleOnFront = !empty($payload['visible_on_front']);
        if ($comment !== '') {
            $shipment->addComment($comment, $notify, $visibleOnFront);
            $shipment->setCustomerNote($comment);
            $shipment->setCustomerNoteNotify($notify);
        }

        $shipment->register();
        $order->setIsInProcess(true);

        $transaction = $this->transactionFactory->create();
        $transaction->addObject($shipment);
        $transaction->addObject($order);
        $transaction->save();

        if ($notify) {
            $this->shipmentSender->send($shipment);
        }

        return [
            'order_id'              => $orderId,
            'increment_id'          => $order->getIncrementId(),
            'shipment_id'           => (int) $shipment->getEntityId(),
            'shipment_increment_id' => $shipment->getIncrementId(),
            'total_qty'             => (float) $shipment->getTotalQty(),
            'source_code'           => $shipment->getExtensionAttributes()?->getSourceCode(),
            'tracks'                => $this->trackSummary($shipment),
            'comment'               => $comment !== '' ? $comment : null,
            'state'                 => $order->getState(),
            'status'                => $order->getStatus(),
            'notified'              => $notify,
        ];
    }

    /**
     * Assign MSI source to shipment so the deduction observer pulls qty from it.
     *
     * - Single-source mode: source_code optional; observer falls back to default source.
     * - Multi-source mode: source_code required. Pass "auto" to let Magento's
     *   Source Selection Algorithm pick the source from product availability.
     *
     * @param Order\Shipment $shipment
     * @param string|null    $sourceCode  explicit code, "auto", or null
     * @param Order          $order
     * @throws Exception
     */
    private function assignSource(Order\Shipment $shipment, ?string $sourceCode, Order $order): void
    {
        $sourceCode = $sourceCode !== null ? trim($sourceCode) : '';

        if ($sourceCode === '' && $this->isSingleSourceMode->execute()) {
            return; // single-source: observer uses default source automatically
        }

        // Auto-resolve from product availability via Source Selection Algorithm
        if ($sourceCode === '' || strtolower($sourceCode) === 'auto') {
            $sourceCode = $this->resolveAutoSource($shipment, $order);
        } else {
            // Explicit code — validate it exists
            try {
                $this->sourceRepository->get($sourceCode);
            } catch (NoSuchEntityException) {
                throw new Exception("Inventory source '$sourceCode' not found");
            }
        }

        $extension = $shipment->getExtensionAttributes() ?: $this->shipmentExtensionFactory->create();
        $extension->setSourceCode($sourceCode);
        $shipment->setExtensionAttributes($extension);
    }

    /**
     * Run the default Source Selection Algorithm over the shipment items and
     * return the single source that covers them.
     *
     * A Magento shipment deducts from ONE source. If the algorithm splits items
     * across multiple sources, throw with the per-source breakdown so the caller
     * can ship per source (one API call each with explicit source_code + items).
     *
     * @param Order\Shipment $shipment
     * @param Order          $order
     * @return string
     * @throws Exception
     */
    private function resolveAutoSource(Order\Shipment $shipment, Order $order): string
    {
        $requestItems = [];
        foreach ($shipment->getAllItems() as $item) {
            if ((float) $item->getQty() <= 0) {
                continue;
            }
            $requestItems[] = $this->itemRequestFactory->create([
                'sku' => $item->getSku(),
                'qty' => (float) $item->getQty(),
            ]);
        }

        if (!$requestItems) {
            throw new Exception("No items to resolve a source for order #{$order->getIncrementId()}");
        }

        $inventoryRequest = $this->getInventoryRequestFromOrder->execute(
            (int) $order->getEntityId(),
            $requestItems
        );
        $result = $this->sourceSelectionService->execute(
            $inventoryRequest,
            $this->getDefaultAlgorithmCode->execute()
        );

        if (!$result->isShippable()) {
            throw new Exception(
                "No inventory source has enough stock to ship order #{$order->getIncrementId()}. "
                . "Provide an explicit source_code or restock."
            );
        }

        // Collect sources the algorithm wants to deduct from
        $perSource = [];
        foreach ($result->getSourceSelectionItems() as $ssItem) {
            if ($ssItem->getQtyToDeduct() <= 0) {
                continue;
            }
            $perSource[$ssItem->getSourceCode()][] = $ssItem->getSku() . ' x' . $ssItem->getQtyToDeduct();
        }

        if (count($perSource) > 1) {
            $breakdown = [];
            foreach ($perSource as $code => $skus) {
                $breakdown[] = "$code: " . implode(', ', $skus);
            }
            throw new Exception(
                "Items span multiple sources for order #{$order->getIncrementId()} — "
                . "ship per source with explicit source_code. Breakdown — " . implode(' | ', $breakdown)
            );
        }

        return (string) array_key_first($perSource);
    }

    /**
     * Build track data rows for ShipmentFactory.
     *
     * ShipmentFactory::prepareTracks() expects an array of data arrays keyed by
     * 'number' (it builds the Track model internally via addData), NOT Track objects.
     *
     * @param array $rows
     * @return array
     * @throws Exception
     */
    private function buildTracks(array $rows): array
    {
        $tracks = [];
        foreach ($rows as $row) {
            $number = trim((string) ($row['track_number'] ?? $row['number'] ?? ''));
            if ($number === '') {
                throw new Exception('track_number is required for each track');
            }
            $tracks[] = [
                'number'       => $number,
                'title'        => (string) ($row['title'] ?? ''),
                // Carrier as custom value — default 'custom' so Magento treats title/number as free text
                'carrier_code' => (string) ($row['carrier_code'] ?? 'custom'),
            ];
        }
        return $tracks;
    }

    /**
     * @param Order\Shipment $shipment
     * @return array
     */
    private function trackSummary(Order\Shipment $shipment): array
    {
        $summary = [];
        foreach ($shipment->getAllTracks() as $track) {
            $summary[] = [
                'track_id'     => (int) $track->getEntityId(),
                'track_number' => $track->getTrackNumber(),
                'title'        => $track->getTitle(),
                'carrier_code' => $track->getCarrierCode(),
            ];
        }
        return $summary;
    }

    /**
     * Build SKU → order_item_id map from order items.
     */
    private function buildSkuMap(Order $order): array
    {
        $map = [];
        foreach ($order->getAllItems() as $item) {
            $map[$item->getSku()] = (int) $item->getItemId();
        }
        return $map;
    }
}
