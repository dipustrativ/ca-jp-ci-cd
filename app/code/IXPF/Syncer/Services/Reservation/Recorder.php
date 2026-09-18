<?php

declare(strict_types=1);

namespace IXPF\Syncer\Services\Reservation;

use IXPF\Syncer\Model\IxpfReservation;
use IXPF\Syncer\Model\ResourceModel\IxpfReservation as ReservationResource;

/**
 * Turns resolved (sku, qty, source) lines into ixpf_reservations rows.
 *
 * Every path into the table goes through here so the derived columns —
 * reservation_type, the batch id — are produced in exactly one place.
 */
class Recorder
{
    public function __construct(
        private readonly ReservationResource $reservationResource
    ) {
    }

    /**
     * A batch id, for a caller that needs one before the rows exist.
     *
     * The inbound endpoint claims its idempotency key against the batch id
     * before writing anything, so the id has to be mintable up front.
     */
    public static function generateBatchId(): string
    {
        return uniqid('res_', true);
    }

    /**
     * Write a batch and return its id.
     *
     * @param array<int, array{sku: string, qty: float, source: string}> $lines
     *        Quantities are signed by the caller: negative removes stock.
     * @param string|null $idempotencyKey Inbound only — the key the caller sent, kept
     *        on the rows so the grid shows which request produced them.
     * @param string|null $batchId Pass one already generated; otherwise a new one is minted.
     */
    public function record(
        string $direction,
        string $reservationStatus,
        ?int $orderId,
        array $lines,
        ?string $eventRef = null,
        string $requestStatus = IxpfReservation::REQUEST_PENDING,
        ?string $information = null,
        ?string $idempotencyKey = null,
        ?string $batchId = null
    ): string {
        $batchId = $batchId ?? self::generateBatchId();

        if (!$lines) {
            return $batchId;
        }

        $rows = [];
        foreach ($lines as $line) {
            $qty = (float)$line['qty'];
            $rows[] = [
                'batch_id' => $batchId,
                'direction' => $direction,
                'order_id' => $orderId,
                'sku' => (string)$line['sku'],
                'stock_source' => (string)$line['source'],
                'qty' => $qty,
                'reservation_type' => IxpfReservation::typeForQty($qty),
                'reservation_status' => $reservationStatus,
                'event_ref' => $eventRef,
                'idempotency_key' => $idempotencyKey,
                'request_status' => $requestStatus,
                'information' => $information,
                'attempts' => 0,
            ];
        }

        $this->reservationResource->addMany($rows);

        return $batchId;
    }

    /**
     * Placeholder source for a line whose warehouse could not be determined.
     *
     * Never a real source code — inventory_source holds lowercase codes like
     * tokyo and hamburg — so it cannot be mistaken for one.
     */
    public const SOURCE_UNRESOLVED = 'unresolved';

    /**
     * Record that a batch could not be built, so nothing was sent.
     *
     * Recorded rather than dropped silently: a reservation we never sent has to
     * be visible in the grid. Callers pass the SKUs and quantities they do know
     * — which is all of them, since only the warehouse was ever in doubt — so
     * the row says "VAN211508 qty 1, source unresolved" instead of three blanks.
     *
     * Marked `skipped`, not `failed`: nothing here can be sent, and the retry
     * cron claims only pending and failed. It needs a human, not a cron.
     *
     * @param array<int, array{sku: string, qty: float, source?: string}> $lines
     */
    public function recordFailure(
        string $direction,
        string $reservationStatus,
        ?int $orderId,
        string $information,
        ?string $eventRef = null,
        array $lines = []
    ): string {
        $rows = [];
        foreach ($lines as $line) {
            $sku = trim((string)($line['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $rows[] = [
                'sku' => $sku,
                'qty' => (float)($line['qty'] ?? 0),
                'source' => (string)($line['source'] ?? '') ?: self::SOURCE_UNRESOLVED,
            ];
        }

        if (!$rows) {
            $rows = [['sku' => '', 'qty' => 0.0, 'source' => self::SOURCE_UNRESOLVED]];
        }

        return $this->record(
            $direction,
            $reservationStatus,
            $orderId,
            $rows,
            $eventRef,
            IxpfReservation::REQUEST_SKIPPED,
            $information
        );
    }
}
