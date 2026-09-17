<?php

declare(strict_types=1);

namespace IXPF\Syncer\Services\Reservation;

use IXPF\Syncer\Model\IxpfReservation;
use IXPF\Syncer\Model\ResourceModel\IxpfReservation as ReservationResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;
use Throwable;

/**
 * Works out the lines a lifecycle event should send.
 *
 * Preference order:
 *
 *   1. Replay the rows recorded at placement. The correct answer whenever it is
 *      available — stock has moved since, so re-running source selection would
 *      name a different warehouse than the one IXPF is actually holding.
 *   2. Read the shipment's source. Authoritative rather than a guess: it is the
 *      warehouse MSI really deducted from, recorded in inventory_shipment_source.
 *   3. Run source selection now. A guess, but a probably-right one.
 *
 * Step 2 exists because shipping is exactly when MSI zeroes the source quantity.
 * By the time an order reaches `complete` it is usually fully shipped, so step 3
 * on its own would find nothing in stock and give up — which is precisely the
 * case it was supposed to cover.
 *
 * Steps 2 and 3 are flagged as inferred so the row records that the source was
 * worked out at event time rather than replayed.
 */
class Replay
{
    public function __construct(
        private readonly ReservationResource $reservationResource,
        private readonly Collector $collector,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Lines for a lifecycle event, replayed if possible and inferred if not.
     *
     * @param array<string, float>|null $skuQty Limit to these per-SKU quantities
     *                                          (partial refunds). Null = everything.
     * @return array{lines: array<int, array{sku: string, qty: float, source: string}>, inferred: bool}
     */
    public function resolve(Order $order, ?array $skuQty = null): array
    {
        $orderId = (int)$order->getEntityId();

        $placement = $this->placementLines($orderId);
        if ($placement) {
            return [
                'lines' => $skuQty === null ? $placement : $this->consume($placement, $skuQty),
                'inferred' => false,
            ];
        }

        // No placement batch. Prefer what the shipment actually deducted from
        // over asking the algorithm to guess against now-empty shelves.
        $lines = $this->shipmentLines($orderId);

        if (!$lines) {
            try {
                $lines = $this->collector->collect($order);
            } catch (Throwable) {
                return ['lines' => [], 'inferred' => true];
            }
        }

        return [
            'lines' => $skuQty === null ? $lines : $this->consume($lines, $skuQty),
            'inferred' => true,
        ];
    }

    /**
     * What each shipment of this order actually took, and from where.
     *
     * inventory_shipment_source is written by MSI when the shipment is created,
     * so this is the real warehouse rather than an inference. An order shipped in
     * parts yields one entry per (shipment, sku).
     *
     * @return array<int, array{sku: string, qty: float, source: string}>
     */
    private function shipmentLines(int $orderId): array
    {
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(
                ['s' => $this->resourceConnection->getTableName('sales_shipment')],
                []
            )
            ->join(
                ['si' => $this->resourceConnection->getTableName('sales_shipment_item')],
                'si.parent_id = s.entity_id',
                ['sku', 'qty']
            )
            ->join(
                ['iss' => $this->resourceConnection->getTableName('inventory_shipment_source')],
                'iss.shipment_id = s.entity_id',
                ['source_code']
            )
            ->where('s.order_id = ?', $orderId)
            ->where('si.qty > 0')
            ->order('s.entity_id ASC');

        $lines = [];
        foreach ($connection->fetchAll($select) as $row) {
            $sku = trim((string)$row['sku']);
            $source = trim((string)$row['source_code']);
            $qty = (float)$row['qty'];

            if ($sku === '' || $source === '' || $qty <= 0) {
                continue;
            }

            $lines[] = ['sku' => $sku, 'qty' => $qty, 'source' => $source];
        }

        return $lines;
    }

    /**
     * Note for the row when the source was not replayed.
     */
    public function inferredNote(int $orderId): string
    {
        $hasSkipped = (bool)array_filter(
            $this->reservationResource->getPlacementRows($orderId, false),
            static fn (array $r): bool => $r['request_status'] === IxpfReservation::REQUEST_SKIPPED
        );

        $why = $hasSkipped
            ? 'the placement reservation never reached IXPF'
            : 'this order has no placement reservation';

        $how = $this->shipmentLines($orderId)
            ? 'taken from the shipment source'
            : 'resolved by source selection at event time';

        return sprintf('Source %s — %s.', $how, $why);
    }

    /**
     * Why nothing could be produced, for the rare case both routes fail.
     */
    public function describeMissing(int $orderId): string
    {
        $rows = $this->reservationResource->getPlacementRows($orderId, false);

        if (!$rows) {
            return 'Nothing to send: this order has no placement reservation, no shipment '
                . 'recording which warehouse it left from, and no inventory source can be '
                . 'resolved for it now.';
        }

        $skipped = array_values(array_filter(
            $rows,
            static fn (array $r): bool => $r['request_status'] === IxpfReservation::REQUEST_SKIPPED
        ));

        if ($skipped) {
            $reason = trim((string)($skipped[0]['information'] ?? ''));

            return 'Placement reservation never reached IXPF, no shipment source is '
                . 'recorded, and no source can be resolved now'
                . ($reason !== '' ? ' — ' . $reason : '.');
        }

        return 'Placement rows exist but produced no lines for this event.';
    }

    /**
     * Every usable placement line for the order, quantities positive.
     *
     * @return array<int, array{sku: string, qty: float, source: string}>
     */
    public function all(int $orderId): array
    {
        return $this->placementLines($orderId);
    }

    /**
     * Placement lines limited to the given per-SKU quantities.
     *
     * @param array<string, float> $skuQty
     * @return array<int, array{sku: string, qty: float, source: string}>
     */
    public function forQuantities(int $orderId, array $skuQty): array
    {
        return $this->consume($this->placementLines($orderId), $skuQty);
    }

    /**
     * @return array<int, array{sku: string, qty: float, source: string}>
     */
    private function placementLines(int $orderId): array
    {
        $lines = [];
        foreach ($this->reservationResource->getPlacementRows($orderId) as $row) {
            $qty = abs((float)$row['qty']);
            if ($row['sku'] === '' || $qty <= 0) {
                continue;
            }
            $lines[] = [
                'sku' => (string)$row['sku'],
                'qty' => $qty,
                'source' => (string)$row['stock_source'],
            ];
        }

        return $lines;
    }

    /**
     * Draw the requested per-SKU quantities from the given lines, source by
     * source, in order.
     *
     * A partial refund of 2 against tokyo:1 + hamburg:2 yields tokyo:1 +
     * hamburg:1. Quantities beyond what is available are dropped rather than
     * invented — the available lines are the ceiling.
     *
     * @param array<int, array{sku: string, qty: float, source: string}> $available
     * @param array<string, float> $skuQty
     * @return array<int, array{sku: string, qty: float, source: string}>
     */
    private function consume(array $available, array $skuQty): array
    {
        $remaining = array_map('abs', $skuQty);
        $lines = [];

        foreach ($available as $line) {
            $sku = $line['sku'];
            $have = abs((float)$line['qty']);

            if ($sku === '' || $have <= 0 || ($remaining[$sku] ?? 0.0) <= 0) {
                continue;
            }

            $take = min($have, $remaining[$sku]);
            $remaining[$sku] -= $take;

            $lines[] = ['sku' => $sku, 'qty' => $take, 'source' => $line['source']];
        }

        return $lines;
    }
}
