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

namespace CarpetAvenue\ExportImport\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * The `sales_order_payment` and `sales_payment_transaction` lookups the
 * transaction export and import need, plus the two writes that cannot go through
 * the model.
 *
 * A transaction row carries `order_id`, `payment_id` and `parent_id`, all
 * instance-local. The CSV names the order by its increment id and the parent by
 * its gateway `txn_id`, so translating between the two is most of the job here.
 * Doing it in SQL keeps the exporter and importer from loading an order and a
 * payment model per transaction. Order-increment-id resolution itself is not
 * repeated — {@see DocumentExtraData} already does it, and is reused.
 *
 * @see Transaction\Importer for why `created_at` and `parent_id` are written with
 *      the raw statement below rather than through the repository.
 */
class TransactionExtraData
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    /**
     * The payment row id of each of a set of orders, in one query.
     *
     * An order has exactly one `sales_order_payment` row (Magento enforces it
     * through `Order::setPayment()`), and `sales_payment_transaction.payment_id`
     * is NOT NULL, so an order without one cannot take an imported transaction
     * at all — which is why the validator treats a missing payment as an error
     * rather than letting the save fail row by row.
     *
     * @param int[] $orderIds
     * @return array<int, int> order id => payment id, orders without one absent
     */
    public function getOrderPaymentIds(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if ($orderIds === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection('sales');
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from(
                    $this->resourceConnection->getTableName('sales_order_payment', 'sales'),
                    ['parent_id', 'entity_id']
                )
                ->where('parent_id IN (?)', $orderIds)
                // A stray second payment row would be a broken install; DESC so
                // that the lowest id is the one fetchPairs() keeps (each row
                // overwrites the previous under the same order id), i.e. the
                // oldest payment rather than an arbitrary one.
                ->order('entity_id DESC')
        );

        $payments = [];
        foreach ($rows as $orderId => $paymentId) {
            $payments[(int)$orderId] = (int)$paymentId;
        }
        return $payments;
    }

    /**
     * The transactions a set of orders already has, keyed for upsert detection.
     *
     * `txn_id` is unique per order and payment, so the pair (order, txn_id) is
     * the key the importer upserts on. The same map answers the parent lookup: a
     * `parent_txn_id` is resolved to a `transaction_id` within the same order.
     *
     * @param int[] $orderIds
     * @return array<int, array<string, int>> order id => txn_id => transaction_id
     */
    public function getTransactionIds(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if ($orderIds === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection('sales');
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('sales_payment_transaction', 'sales'),
                ['order_id', 'txn_id', 'transaction_id']
            )
            ->where('order_id IN (?)', $orderIds)
            ->order('transaction_id ASC');

        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $txnId = (string)$row['txn_id'];
            if ($txnId !== '') {
                $map[(int)$row['order_id']][$txnId] = (int)$row['transaction_id'];
            }
        }
        return $map;
    }

    /**
     * Write the two columns the model refuses to keep.
     *
     * `created_at`: `Transaction::beforeSave()` stamps the current time on every
     * transaction that has no id yet, unconditionally, so a created transaction
     * always lands with today's date and the exported one is lost. There is no
     * hook to opt out of it — the repository, the resource and `save()` all go
     * through `beforeSave()`.
     *
     * `parent_id`: a pure re-resolution of `parent_txn_id` to a local id. The
     * sales resource model does this itself in its own `_beforeSave()` — but
     * only against transactions already in the database, so a parent created
     * later in the same run is missed. This fills those in afterwards. It only
     * ever WRITES a resolved id, never clears one, so the two cannot fight.
     *
     * (The model's `setParentTxnId()` is avoided throughout: it loads the parent
     * and brings in the auto-linking that closes an authorization when a capture
     * is attached to it — behaviour that belongs to a live payment, not to a
     * migration.)
     *
     * Both are corrections to a row that is already saved, so one statement per
     * transaction is the whole cost.
     *
     * @param int $transactionId the local `transaction_id`
     * @param string $createdAt exported value, '' to leave the stamped one alone
     * @param int|null $parentId resolved local parent, null to leave it unset
     */
    public function fixImportedRow(int $transactionId, string $createdAt, ?int $parentId): void
    {
        if ($transactionId <= 0) {
            return;
        }

        $values = [];
        if ($createdAt !== '') {
            $values['created_at'] = $createdAt;
        }
        if ($parentId !== null) {
            $values['parent_id'] = $parentId;
        }
        if ($values === []) {
            return;
        }

        $connection = $this->resourceConnection->getConnection('sales');
        $connection->update(
            $this->resourceConnection->getTableName('sales_payment_transaction', 'sales'),
            $values,
            ['transaction_id = ?' => $transactionId]
        );
    }
}
