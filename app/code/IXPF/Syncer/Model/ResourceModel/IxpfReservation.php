<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\ResourceModel;

use IXPF\Syncer\Model\IxpfReservation as ReservationModel;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Throwable;
use Zend_Db_Expr;

/**
 * Reservation rows.
 *
 * The hot paths here talk to the connection directly rather than through the
 * model, matching how Helper\Webhook drives ixpf_webhook_jobs — a batch is
 * always read and written as a set, never row by row.
 */
class IxpfReservation extends AbstractDb
{
    public const TABLE = 'ixpf_reservations';
    public const ENTITY = 'entity_id';

    private const INSERT_CHUNK = 500;

    /** A batch left in `processing` for longer than this was abandoned mid-send. */
    public const STUCK_MINUTES = 15;

    public function _construct()
    {
        $this->_init(self::TABLE, self::ENTITY);
    }

    /**
     * Insert a batch of rows.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return int Rows written
     */
    public function addMany(array $rows): int
    {
        if (!$rows) {
            return 0;
        }

        $connection = $this->getConnection();
        $table = $this->getMainTable();
        $written = 0;

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $written += (int)$connection->insertMultiple($table, $chunk);
        }

        return $written;
    }

    /**
     * Read every row of a batch, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBatch(string $batchId): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable())
            ->where('batch_id = ?', $batchId)
            ->order(self::ENTITY . ' ASC');

        return $connection->fetchAll($select);
    }

    /**
     * Claim a batch for sending: pending -> processing, once.
     *
     * Returns false when another worker already holds it. This is what stops an
     * observer and a cron tick from both POSTing the same reservation.
     */
    public function claimBatch(string $batchId): bool
    {
        $connection = $this->getConnection();

        try {
            $affected = $connection->update(
                $this->getMainTable(),
                [
                    'request_status' => ReservationModel::REQUEST_PROCESSING,
                    'updated_at' => new Zend_Db_Expr('NOW()'),
                ],
                [
                    'batch_id = ?' => $batchId,
                    'request_status IN (?)' => [
                        ReservationModel::REQUEST_PENDING,
                        ReservationModel::REQUEST_FAILED,
                    ],
                ]
            );
        } catch (Throwable) {
            return false;
        }

        return $affected > 0;
    }

    /**
     * Record the outcome of a batch's delivery.
     */
    public function markBatch(
        string $batchId,
        string $requestStatus,
        ?string $information = null,
        ?string $response = null,
        ?string $payload = null
    ): void {
        $data = [
            'request_status' => $requestStatus,
            'information' => $information,
            'attempts' => new Zend_Db_Expr('attempts + 1'),
            'updated_at' => new Zend_Db_Expr('NOW()'),
        ];

        if ($response !== null) {
            $data['response'] = $response;
        }
        if ($payload !== null) {
            $data['payload'] = $payload;
        }

        $this->getConnection()->update($this->getMainTable(), $data, ['batch_id = ?' => $batchId]);
    }

    /**
     * Note that a fire-and-forget batch left the building.
     *
     * Deliberately not markBatch(): the batch stays `processing` because nothing
     * has confirmed it yet, and `attempts` is bumped here because this was a real
     * delivery attempt — that is what eventually stops a batch IXPF never
     * acknowledges from being re-sent forever.
     */
    public function recordSent(string $batchId, string $payload): void
    {
        $this->getConnection()->update(
            $this->getMainTable(),
            [
                'payload' => $payload,
                'attempts' => new Zend_Db_Expr('attempts + 1'),
                'updated_at' => new Zend_Db_Expr('NOW()'),
            ],
            ['batch_id = ?' => $batchId]
        );
    }

    /**
     * Settle a batch from IXPF's acknowledgement.
     *
     * Unlike markBatch() this leaves `attempts` alone: recordSent() already
     * counted the delivery, and the acknowledgement is the answer to it, not a
     * second try.
     */
    public function markAcknowledged(
        string $batchId,
        string $requestStatus,
        ?string $information,
        ?string $response
    ): void {
        $this->getConnection()->update(
            $this->getMainTable(),
            [
                'request_status' => $requestStatus,
                'information' => $information,
                'response' => $response,
                'updated_at' => new Zend_Db_Expr('NOW()'),
            ],
            ['batch_id = ?' => $batchId]
        );
    }

    /**
     * The batch an acknowledgement refers to.
     *
     * IXPF sends back the `acknowledged_id` it was given — the entity_id of the
     * batch's first row — and this turns it back into the batch.
     */
    public function getBatchIdByEntityId(int $entityId): ?string
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['batch_id'])
            ->where(self::ENTITY . ' = ?', $entityId)
            ->limit(1);

        $batchId = $connection->fetchOne($select);

        return $batchId === false || $batchId === '' ? null : (string)$batchId;
    }

    /**
     * Record the outcome of individual lines.
     *
     * Inbound batches need this rather than markBatch(): one malformed line does
     * not stop the others, so a batch can genuinely be part applied and part
     * failed, and a row claiming `applied` when nothing was written would be a lie.
     *
     * @param array<int, array{entity_id: int, status: string, information: string|null}> $outcomes
     */
    public function markLines(array $outcomes): void
    {
        if (!$outcomes) {
            return;
        }

        $connection = $this->getConnection();
        $table = $this->getMainTable();

        // Grouped so a batch of identical outcomes is one UPDATE, not one per row.
        $grouped = [];
        foreach ($outcomes as $outcome) {
            $key = $outcome['status'] . "\0" . (string)$outcome['information'];
            $grouped[$key]['status'] = $outcome['status'];
            $grouped[$key]['information'] = $outcome['information'];
            $grouped[$key]['ids'][] = (int)$outcome['entity_id'];
        }

        foreach ($grouped as $group) {
            $connection->update(
                $table,
                [
                    'request_status' => $group['status'],
                    'information' => $group['information'],
                    'attempts' => new Zend_Db_Expr('attempts + 1'),
                    'updated_at' => new Zend_Db_Expr('NOW()'),
                ],
                [self::ENTITY . ' IN (?)' => $group['ids']]
            );
        }
    }

    /**
     * Batch ids waiting to be sent, oldest first.
     *
     * @param string[] $requestStatuses
     * @return string[]
     * @throws LocalizedException
     */
    public function getDispatchableBatchIds(array $requestStatuses, int $maxAttempts, int $limit): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['batch_id'])
            ->where('direction = ?', ReservationModel::DIRECTION_OUTBOUND)
            ->where('request_status IN (?)', $requestStatuses)
            ->where('attempts < ?', $maxAttempts)
            ->group('batch_id')
            ->order('MIN(' . self::ENTITY . ') ASC')
            ->limit($limit);

        return $connection->fetchCol($select);
    }

    /**
     * Release batches abandoned mid-send so a later run can pick them up.
     */
    public function recoverStuckBatches(): int
    {
        $connection = $this->getConnection();

        return (int)$connection->update(
            $this->getMainTable(),
            [
                'request_status' => ReservationModel::REQUEST_PENDING,
                'updated_at' => new Zend_Db_Expr('NOW()'),
            ],
            [
                'direction = ?' => ReservationModel::DIRECTION_OUTBOUND,
                'request_status = ?' => ReservationModel::REQUEST_PROCESSING,
                'updated_at < (NOW() - INTERVAL ' . self::STUCK_MINUTES . ' MINUTE)' => null,
            ]
        );
    }

    /**
     * The placement lines for an order, in the order they were recorded.
     *
     * Lifecycle events replay these instead of re-running source selection —
     * stock has moved since, so the algorithm would name a different warehouse.
     *
     * `skipped` rows are excluded by default. They record a placement that never
     * reached IXPF and carry a placeholder source, so replaying them would tell
     * IXPF to release a hold it never took, against a warehouse that does not
     * exist. Pass $sendableOnly = false only to explain why a replay found
     * nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPlacementRows(int $orderId, bool $sendableOnly = true): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable())
            ->where('order_id = ?', $orderId)
            ->where('direction = ?', ReservationModel::DIRECTION_OUTBOUND)
            ->where('reservation_status = ?', ReservationModel::RESERVATION_PENDING)
            ->order(self::ENTITY . ' ASC');

        if ($sendableOnly) {
            $select->where('request_status != ?', ReservationModel::REQUEST_SKIPPED);
        }

        return $connection->fetchAll($select);
    }

    /**
     * Has this lifecycle stage already been recorded for the order?
     *
     * Refunds pass $eventRef (the creditmemo id) because several partial refunds
     * are legitimate and each must send; completed and cancelled must not repeat.
     */
    public function hasStatus(int $orderId, string $reservationStatus, ?string $eventRef = null): bool
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['cnt' => 'COUNT(*)'])
            ->where('order_id = ?', $orderId)
            ->where('reservation_status = ?', $reservationStatus);

        if ($eventRef !== null) {
            $select->where('event_ref = ?', $eventRef);
        }

        return (int)$connection->fetchOne($select) > 0;
    }
}
