<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Order;

use Exception;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryApi\Api\GetSourcesAssignedToStockOrderedByPriorityInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\InventoryCatalogApi\Model\IsSingleSourceModeInterface;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
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
    /**
     * Group key for items shipped without an explicit source in single-source mode
     * (deduction observer falls back to the default source).
     */
    private const NO_SOURCE = '';

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
        private readonly StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver,
        private readonly GetSourcesAssignedToStockOrderedByPriorityInterface $getSourcesAssignedToStock,
        private readonly GetSourceItemsBySkuInterface $getSourceItemsBySku,
    ) {
    }

    /**
     * Create shipment(s) for an order with custom tracks and comment.
     *
     * A Magento shipment deducts stock from ONE source. Items resolving to different
     * sources are split into one shipment per source (same as the admin "Ship" flow),
     * all saved in a single transaction — either every shipment is created or none.
     *
     * Payload:
     * {
     *   "source_code": "hamburg"|"auto",                              // optional default for items
     *   "items":   [{"order_item_id"|"sku": ..., "qty": n, "source_code": "..."}],
     *   "tracks":  [{"track_number": "...", "title": "...", "carrier_code": "custom", "source_code": "..."}],
     *   "comment": "free text",
     *   "notify":  true|false,
     *   "visible_on_front": true|false
     * }
     *
     * Item source: items[].source_code → top-level source_code → auto (Source Selection
     * Algorithm; single-source mode → default source).
     * Track without source_code is added to every shipment; with source_code only to
     * that source's shipment.
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

        $defaultSource = $this->normalizeSourceCode($payload['source_code'] ?? null);
        $lines = $this->parseItems($order, $payload['items'], $defaultSource);

        // source_code => [order_item_id => qty]
        $groups = $this->groupBySource($order, $lines);

        $tracksBySource = $this->buildTracksBySource($payload['tracks'] ?? [], array_keys($groups));

        $comment = trim((string) ($payload['comment'] ?? ''));
        $notify = !empty($payload['notify']);
        $visibleOnFront = !empty($payload['visible_on_front']);

        $shipments = [];
        foreach ($groups as $sourceCode => $qtys) {
            $sourceCode = (string) $sourceCode;
            $tracks = array_merge($tracksBySource['*'], $tracksBySource[$sourceCode] ?? []);

            $shipment = $this->shipmentFactory->create($order, $qtys, $tracks);
            if (!$shipment || !$shipment->getTotalQty()) {
                throw new Exception(
                    "Cannot create shipment without items for order #{$order->getIncrementId()}"
                    . ($sourceCode !== self::NO_SOURCE ? " (source '$sourceCode')" : '')
                );
            }

            // MSI source — which source qty deducts from (mirrors Magento admin source selection)
            if ($sourceCode !== self::NO_SOURCE) {
                $extension = $shipment->getExtensionAttributes() ?: $this->shipmentExtensionFactory->create();
                $extension->setSourceCode($sourceCode);
                $shipment->setExtensionAttributes($extension);
            }

            if ($comment !== '') {
                $shipment->addComment($comment, $notify, $visibleOnFront);
                $shipment->setCustomerNote($comment);
                $shipment->setCustomerNoteNotify($notify);
            }

            // register() updates order item qty_shipped, so the next group's
            // ShipmentFactory::create() sees the reduced qty to ship.
            $shipment->register();
            $shipments[$sourceCode] = $shipment;
        }

        $order->setIsInProcess(true);

        $transaction = $this->transactionFactory->create();
        foreach ($shipments as $shipment) {
            $transaction->addObject($shipment);
        }
        $transaction->addObject($order);
        try {
            $transaction->save();
        } catch (LocalizedException $e) {
            // Source deduction failure (e.g. not enough qty in source) rolls back every shipment
            throw new Exception($e->getMessage() . ' Requested — ' . $this->describeGroups($order, $groups));
        }

        if ($notify) {
            foreach ($shipments as $shipment) {
                $this->shipmentSender->send($shipment);
            }
        }

        $summaries = array_values(array_map(fn($shipment) => $this->shipmentSummary($shipment), $shipments));
        $first = $summaries[0];

        return [
            'order_id'              => $orderId,
            'increment_id'          => $order->getIncrementId(),
            // Top-level fields describe the first shipment (backward compatible single-shipment shape)
            'shipment_id'           => $first['shipment_id'],
            'shipment_increment_id' => $first['shipment_increment_id'],
            'total_qty'             => $first['total_qty'],
            'source_code'           => $first['source_code'],
            'tracks'                => $first['tracks'],
            'shipments'             => $summaries,
            'comment'               => $comment !== '' ? $comment : null,
            'state'                 => $order->getState(),
            'status'                => $order->getStatus(),
            'notified'              => $notify,
        ];
    }

    /**
     * Resolve payload items to order item lines.
     *
     * @param Order $order
     * @param array $items
     * @param string|null $defaultSource
     * @return array<int, array{order_item_id: int, qty: float, source_code: ?string}>
     * @throws Exception
     */
    private function parseItems(Order $order, array $items, ?string $defaultSource): array
    {
        $skuToItemId = $this->buildSkuMap($order);
        $lines = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new Exception("Each item must be an object with 'order_item_id' or 'sku' and 'qty'");
            }
            $orderItemId = null;
            if (!empty($item['order_item_id'])) {
                $orderItemId = (int) $item['order_item_id'];
                if (!$order->getItemById($orderItemId)) {
                    throw new Exception("Order item #$orderItemId not found in order #{$order->getIncrementId()}");
                }
            } elseif (!empty($item['sku'])) {
                $orderItemId = $skuToItemId[$item['sku']] ?? null;
                if (!$orderItemId) {
                    throw new Exception("SKU '{$item['sku']}' not found in order #{$order->getIncrementId()}");
                }
            } else {
                throw new Exception("Each item requires 'order_item_id' or 'sku'");
            }
            // Configurable child shares its SKU with the parent; qty ships on the parent item
            $parent = $order->getItemById($orderItemId)->getParentItem();
            if ($parent && $parent->getProductType() !== 'bundle') {
                $orderItemId = (int) $parent->getItemId();
            }
            if (!isset($item['qty']) || !is_numeric($item['qty']) || (float) $item['qty'] <= 0) {
                throw new Exception("Each item requires a positive 'qty'");
            }
            $lines[] = [
                'order_item_id' => $orderItemId,
                'qty'           => (float) $item['qty'],
                'source_code'   => $this->normalizeSourceCode($item['source_code'] ?? null) ?? $defaultSource,
            ];
        }

        // Same order item may be split across sources — total must not exceed qty left to ship
        $totals = [];
        foreach ($lines as $line) {
            $totals[$line['order_item_id']] = ($totals[$line['order_item_id']] ?? 0) + $line['qty'];
        }
        foreach ($totals as $orderItemId => $total) {
            $orderItem = $order->getItemById($orderItemId);
            $toShip = (float) $orderItem->getQtyToShip();
            if ($total > $toShip) {
                throw new Exception(
                    "Requested qty $total for SKU '{$orderItem->getSku()}' exceeds qty left to ship ($toShip)"
                );
            }
        }

        return $lines;
    }

    /**
     * Group lines by source: explicit codes are validated, auto lines are allocated by
     * the Source Selection Algorithm (single-source mode → no source / default).
     *
     * @param Order $order
     * @param array $lines
     * @return array<string, array<int, float>>
     * @throws Exception
     */
    private function groupBySource(Order $order, array $lines): array
    {
        $groups = [];
        $autoLines = [];
        $orderSources = null;

        foreach ($lines as $line) {
            $sourceCode = $line['source_code'];
            if ($sourceCode === null || strtolower($sourceCode) === 'auto') {
                $autoLines[] = $line;
                continue;
            }
            $orderSources ??= $this->getOrderSourceCodes($order);
            $this->validateSource($order, $sourceCode, $line['order_item_id'], $orderSources);
            $groups[$sourceCode][$line['order_item_id']] = ($groups[$sourceCode][$line['order_item_id']] ?? 0)
                + $line['qty'];
        }

        if ($autoLines) {
            if ($this->isSingleSourceMode->execute()) {
                foreach ($autoLines as $line) {
                    $groups[self::NO_SOURCE][$line['order_item_id']] =
                        ($groups[self::NO_SOURCE][$line['order_item_id']] ?? 0) + $line['qty'];
                }
            } else {
                foreach ($this->allocateAuto($order, $autoLines) as $sourceCode => $qtys) {
                    foreach ($qtys as $orderItemId => $qty) {
                        $groups[$sourceCode][$orderItemId] = ($groups[$sourceCode][$orderItemId] ?? 0) + $qty;
                    }
                }
            }
        }

        return $groups;
    }

    /**
     * Explicit source must exist, be enabled, belong to the order's stock and carry the SKU.
     *
     * @throws Exception
     */
    private function validateSource(Order $order, string $sourceCode, int $orderItemId, array $orderSources): void
    {
        try {
            $source = $this->sourceRepository->get($sourceCode);
        } catch (NoSuchEntityException) {
            throw new Exception("Inventory source '$sourceCode' not found");
        }
        if (!$source->isEnabled()) {
            throw new Exception("Inventory source '$sourceCode' is disabled");
        }
        if ($orderSources && !in_array($sourceCode, $orderSources, true)) {
            throw new Exception(
                "Inventory source '$sourceCode' is not assigned to the stock of order #{$order->getIncrementId()}. "
                . 'Allowed: ' . implode(', ', $orderSources)
            );
        }

        $sku = (string) $order->getItemById($orderItemId)->getSku();
        foreach ($this->getSourceItemsBySku->execute($sku) as $sourceItem) {
            if ($sourceItem->getSourceCode() === $sourceCode) {
                return;
            }
        }
        throw new Exception("SKU '$sku' is not assigned to inventory source '$sourceCode'");
    }

    /**
     * Source codes linked to the stock of the order's website ([] when not resolvable).
     *
     * @return string[]
     */
    private function getOrderSourceCodes(Order $order): array
    {
        try {
            $websiteId = (int) $order->getStore()->getWebsiteId();
            $stockId = (int) $this->stockByWebsiteIdResolver->execute($websiteId)->getStockId();
            return array_map(
                fn($source) => (string) $source->getSourceCode(),
                $this->getSourcesAssignedToStock->execute($stockId)
            );
        } catch (Exception) {
            return [];
        }
    }

    /**
     * Run the default Source Selection Algorithm over auto lines and return the
     * per-source allocation. One order item may be split across several sources.
     *
     * @param Order $order
     * @param array $lines
     * @return array<string, array<int, float>>
     * @throws Exception
     */
    private function allocateAuto(Order $order, array $lines): array
    {
        // SSA works per SKU — aggregate requested qty per SKU and remember the order item
        $qtyBySku = [];
        $itemIdBySku = [];
        foreach ($lines as $line) {
            $sku = (string) $order->getItemById($line['order_item_id'])->getSku();
            $qtyBySku[$sku] = ($qtyBySku[$sku] ?? 0) + $line['qty'];
            $itemIdBySku[$sku] = $line['order_item_id'];
        }

        $requestItems = [];
        foreach ($qtyBySku as $sku => $qty) {
            $requestItems[] = $this->itemRequestFactory->create(['sku' => (string) $sku, 'qty' => $qty]);
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

        $groups = [];
        foreach ($result->getSourceSelectionItems() as $ssItem) {
            $qty = (float) $ssItem->getQtyToDeduct();
            if ($qty <= 0) {
                continue;
            }
            $orderItemId = $itemIdBySku[$ssItem->getSku()] ?? null;
            if ($orderItemId === null) {
                continue;
            }
            $groups[$ssItem->getSourceCode()][$orderItemId] =
                ($groups[$ssItem->getSourceCode()][$orderItemId] ?? 0) + $qty;
        }

        return $groups;
    }

    /**
     * Build track data rows for ShipmentFactory, keyed by target source
     * ('*' = every shipment).
     *
     * ShipmentFactory::prepareTracks() expects an array of data arrays keyed by
     * 'number' (it builds the Track model internally via addData), NOT Track objects.
     *
     * @param array    $rows
     * @param string[] $sourceCodes sources that get a shipment
     * @return array<string, array>
     * @throws Exception
     */
    private function buildTracksBySource(array $rows, array $sourceCodes): array
    {
        $tracks = ['*' => []];
        foreach ($rows as $row) {
            $number = trim((string) ($row['track_number'] ?? $row['number'] ?? ''));
            if ($number === '') {
                throw new Exception('track_number is required for each track');
            }
            $target = $this->normalizeSourceCode($row['source_code'] ?? null) ?? '*';
            if ($target !== '*' && !in_array($target, $sourceCodes, true)) {
                throw new Exception(
                    "Track '$number' has source_code '$target' but no items ship from that source. "
                    . 'Shipping sources: ' . implode(', ', array_filter($sourceCodes) ?: ['default'])
                );
            }
            $tracks[$target][] = [
                'number'       => $number,
                'title'        => (string) ($row['title'] ?? ''),
                // Carrier as custom value — default 'custom' so Magento treats title/number as free text
                'carrier_code' => (string) ($row['carrier_code'] ?? 'custom'),
            ];
        }
        return $tracks;
    }

    private function normalizeSourceCode(mixed $sourceCode): ?string
    {
        if (!is_scalar($sourceCode)) {
            return null;
        }
        $sourceCode = trim((string) $sourceCode);
        return $sourceCode === '' ? null : $sourceCode;
    }

    /**
     * "hamburg: SKU-1 x1, SKU-2 x2 | botepp: SKU-3 x1"
     */
    private function describeGroups(Order $order, array $groups): string
    {
        $parts = [];
        foreach ($groups as $sourceCode => $qtys) {
            $skus = [];
            foreach ($qtys as $orderItemId => $qty) {
                $skus[] = $order->getItemById($orderItemId)?->getSku() . ' x' . $qty;
            }
            $parts[] = ($sourceCode !== self::NO_SOURCE ? $sourceCode : 'default') . ': ' . implode(', ', $skus);
        }
        return implode(' | ', $parts);
    }

    /**
     * @param Order\Shipment $shipment
     * @return array
     */
    private function shipmentSummary(Order\Shipment $shipment): array
    {
        $items = [];
        foreach ($shipment->getAllItems() as $item) {
            if ((float) $item->getQty() <= 0) {
                continue;
            }
            $items[] = [
                'order_item_id' => (int) $item->getOrderItemId(),
                'sku'           => $item->getSku(),
                'qty'           => (float) $item->getQty(),
            ];
        }

        return [
            'shipment_id'           => (int) $shipment->getEntityId(),
            'shipment_increment_id' => $shipment->getIncrementId(),
            'source_code'           => $shipment->getExtensionAttributes()?->getSourceCode(),
            'total_qty'             => (float) $shipment->getTotalQty(),
            'items'                 => $items,
            'tracks'                => $this->trackSummary($shipment),
        ];
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
