<?php

declare(strict_types=1);

namespace IXPF\Syncer\Services\Reservation;

use Magento\Framework\App\ResourceConnection;
use Throwable;

/**
 * One-shot claim on an inbound reservation request's `idempotency_key`.
 *
 * IXPF retries a request it did not get an answer to — a timeout, a dropped
 * connection — and without this the retry would apply the same delta a second
 * time. Stock is adjusted relatively here (new = current + qty), so a replayed
 * batch is not a no-op the way an absolute write would be: it moves the quantity
 * again.
 *
 * The INSERT *is* the claim. The unique index on idempotency_key decides the
 * winner, so two retries arriving at once still apply exactly once — a
 * SELECT-then-INSERT check could let both pass the SELECT before either wrote.
 *
 * Keys are never released. A key is a request's identity, not a lock: once a key
 * has been answered, that answer stands, whether the batch applied or failed. A
 * caller that wants another attempt sends a new key.
 */
class IdempotencyGuard
{
    public const TABLE = 'ixpf_reservation_idempotency';

    /** Matches the column width; a longer key would be silently truncated into a collision. */
    public const MAX_LENGTH = 128;

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Claim the key for this batch.
     *
     * @return array{batch_id: string, created_at: string}|null Null when the claim
     *         succeeded; the original request's details when the key was already used.
     */
    public function claim(string $idempotencyKey, string $batchId): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        try {
            $connection->insert($table, [
                'idempotency_key' => $idempotencyKey,
                'batch_id' => $batchId,
            ]);

            return null;
        } catch (Throwable $e) {
            $existing = $this->find($idempotencyKey);

            // No row means the insert failed for some other reason — a missing
            // table, a dead connection — and swallowing that as "duplicate"
            // would tell IXPF its request was already handled when it was not.
            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }
    }

    /**
     * @return array{batch_id: string, created_at: string}|null
     */
    public function find(string $idempotencyKey): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE), ['batch_id', 'created_at'])
            ->where('idempotency_key = ?', $idempotencyKey)
            ->limit(1);

        $row = $connection->fetchRow($select);

        if (!$row) {
            return null;
        }

        return [
            'batch_id' => (string)$row['batch_id'],
            'created_at' => (string)$row['created_at'],
        ];
    }
}
