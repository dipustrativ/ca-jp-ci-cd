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

namespace CarpetAvenue\ExportImport\Model\Transaction;

use CarpetAvenue\Core\Logger\Logger;
use CarpetAvenue\ExportImport\Model\Order\ValidationResult;
use CarpetAvenue\ExportImport\Model\ResourceModel\DocumentExtraData;
use CarpetAvenue\ExportImport\Model\ResourceModel\TransactionExtraData;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order\Payment\Transaction;

/**
 * Applies a validated payment-transaction CSV: upserts transactions by
 * order + `txn_id` against orders that already exist here.
 *
 *  - new (order, txn_id)      -> CREATE the transaction from the CSV values,
 *                                attached to the order's payment
 *  - existing (order, txn_id) -> UPDATE `is_closed`, and fill in a `txn_type` or
 *                                an `additional_information` payload that is
 *                                missing locally. An existing payload is never
 *                                overwritten — it is the gateway's own record of
 *                                what happened.
 *
 * Like the order and document importers, this writes the CSV values **verbatim**.
 * It does NOT go through `Payment::addTransaction()` /
 * `Payment\Transaction\Builder`, and it never calls a gateway. Beyond the usual
 * reason (those paths recalculate and can capture), transaction auto-linking is
 * the specific hazard: attaching a capture to an authorization through the model
 * closes the authorization, and voiding likewise, so replaying a migrated
 * payment history through it would rewrite the very flags the file carries.
 *
 * ## Two columns are written with SQL after the save
 *
 * `created_at` cannot survive the model, and `parent_id` only partly can — see
 * {@see TransactionExtraData::fixImportedRow()} for exactly why. The parent link
 * is finished in a second pass over everything the run saved, so a file may list
 * a child before its parent; the exporter still writes parents first.
 *
 * Nothing is written unless validation passes first.
 */
class Importer
{
    public function __construct(
        private readonly Schema $schema,
        private readonly Validator $validator,
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly DocumentExtraData $orderData,
        private readonly TransactionExtraData $extraData,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array{header: string[], rows: array<int, array{line: int, data: array<string, string>}>} $parsed
     * @return array{
     *     success: bool,
     *     validation: ValidationResult,
     *     created: string[],
     *     updated: string[],
     *     parents_linked: int,
     *     failed: array<string, string>
     * }
     */
    public function import(array $parsed): array
    {
        $validation = $this->validator->validate($parsed);

        if (!$validation->isValid()) {
            $this->logger->warning(sprintf(
                'Import aborted — transaction validation failed with %d error(s).',
                $validation->getSummary()['errors']
            ));
            return [
                'success' => false,
                'validation' => $validation,
                'created' => [],
                'updated' => [],
                'parents_linked' => 0,
                'failed' => [],
            ];
        }

        $rows = $parsed['rows'];
        $orderIds = $this->orderData->resolveOrderIds(
            array_map(static fn ($row) => (string)($row['data']['order_increment_id'] ?? ''), $rows)
        );
        $paymentIds = $this->extraData->getOrderPaymentIds(array_values($orderIds));
        // Starts as what the database already has and grows with everything this
        // run creates, so the parent pass can resolve either.
        $txnIdsByOrder = $this->extraData->getTransactionIds(array_values($orderIds));

        $plan = $validation->getPlan();
        $created = [];
        $updated = [];
        $failed = [];
        /** @var array<int, array{data: array<string, string>, id: int, order_id: int, created: bool}> */
        $saved = [];

        foreach ($rows as $row) {
            $data = $row['data'];
            $key = $this->schema->rowKey($data);
            $orderIncrementId = (string)($data['order_increment_id'] ?? '');
            $orderId = $orderIds[$orderIncrementId] ?? 0;
            $mode = $plan[$key]['mode'] ?? 'create';

            try {
                if ($orderId <= 0 || !isset($paymentIds[$orderId])) {
                    // Validation rejects both, so reaching here means the order
                    // or its payment vanished between the check and the write.
                    throw new NoSuchEntityException(
                        __('Order %1 or its payment record disappeared before import.', $orderIncrementId)
                    );
                }

                if ($mode === 'update') {
                    $id = $this->updateTransaction(
                        (int)($txnIdsByOrder[$orderId][(string)($data['txn_id'] ?? '')] ?? 0),
                        $data
                    );
                    $updated[] = $key;
                } else {
                    $id = $this->createTransaction($data, $orderId, $paymentIds[$orderId]);
                    $created[] = $key;
                }

                $txnIdsByOrder[$orderId][(string)($data['txn_id'] ?? '')] = $id;
                $saved[] = [
                    'data' => $data,
                    'id' => $id,
                    'order_id' => $orderId,
                    'created' => $mode !== 'update',
                ];
            } catch (\Throwable $e) {
                $failed[$key] = $e->getMessage();
                $this->logger->error(sprintf(
                    'Import failed for transaction %s (%s): %s',
                    $key,
                    $mode,
                    $e->getMessage()
                ));
            }
        }

        $parentsLinked = $this->fixSavedRows($saved, $txnIdsByOrder);

        $this->logger->info(sprintf(
            'Import finished (transactions): %d created, %d updated, %d failed, %d parent link(s) resolved.',
            count($created),
            count($updated),
            count($failed),
            $parentsLinked
        ));

        return [
            'success' => true,
            'validation' => $validation,
            'created' => $created,
            'updated' => $updated,
            'parents_linked' => $parentsLinked,
            'failed' => $failed,
        ];
    }

    /**
     * Create one transaction from its CSV row.
     *
     * Field-by-field `setData()` rather than the model's own setters: those
     * validate against a live payment (`setTxnType()` rejects anything outside
     * Magento's six types, `setParentTxnId()` loads the parent and can close it)
     * and the CSV is authoritative here. `txn_type` is checked by the validator
     * instead, before anything is written.
     *
     * @param array<string, string> $data
     * @return int the local `transaction_id`
     */
    private function createTransaction(array $data, int $orderId, int $paymentId): int
    {
        /** @var Transaction $transaction */
        $transaction = $this->transactionRepository->create();
        $this->applyMappedFields($transaction, $data);
        $transaction->setOrderId($orderId);
        $transaction->setPaymentId($paymentId);
        $transaction->setData('additional_information', $this->decodeAdditionalInformation($data));
        // parent_id is left to the resource model, which resolves parent_txn_id
        // against the transactions already stored, and to fixSavedRows() for a
        // parent this same run created later.
        $transaction->setData('parent_id', null);

        $this->transactionRepository->save($transaction);
        return (int)$transaction->getTransactionId();
    }

    /**
     * Update an existing transaction: its `is_closed` flag, plus a `txn_type` or
     * gateway payload that is missing locally.
     *
     * `txn_id`, the order and the payment are the key — they are what matched
     * this row — and `created_at` is left as it is: the stored transaction is
     * already the record of when the gateway was called.
     *
     * @param array<string, string> $data
     * @return int the local `transaction_id`
     */
    private function updateTransaction(int $transactionId, array $data): int
    {
        if ($transactionId <= 0) {
            throw new NoSuchEntityException(
                __('Transaction %1 disappeared before update.', (string)($data['txn_id'] ?? ''))
            );
        }

        /** @var Transaction $transaction */
        $transaction = $this->transactionRepository->get($transactionId);

        $isClosed = $data['is_closed'] ?? '';
        if ($isClosed !== '') {
            $transaction->setIsClosed((int)(float)$isClosed);
        }

        $txnType = $data['txn_type'] ?? '';
        if ($txnType !== '' && (string)$transaction->getTxnType() === '') {
            $transaction->setData('txn_type', $txnType);
        }

        $additional = $this->decodeAdditionalInformation($data);
        if ($additional !== null && !$transaction->getData('additional_information')) {
            $transaction->setData('additional_information', $additional);
        }

        $this->transactionRepository->save($transaction);
        return (int)$transaction->getTransactionId();
    }

    /**
     * Second pass: restore `created_at` on created rows and resolve every
     * `parent_txn_id` to a local `parent_id`.
     *
     * Runs after all inserts precisely so that a parent listed after its child
     * still resolves. A `parent_txn_id` that matches nothing on the order is left
     * unset — the validator has already warned about it, and the transaction is
     * a complete record of the gateway call without the link.
     *
     * @param array<int, array{data: array<string, string>, id: int, order_id: int, created: bool}> $saved
     * @param array<int, array<string, int>> $txnIdsByOrder
     * @return int how many parent links were written
     */
    private function fixSavedRows(array $saved, array $txnIdsByOrder): int
    {
        $linked = 0;
        foreach ($saved as $entry) {
            $data = $entry['data'];
            $parentTxnId = (string)($data['parent_txn_id'] ?? '');
            $parentId = null;

            if ($parentTxnId !== '') {
                $candidate = (int)($txnIdsByOrder[$entry['order_id']][$parentTxnId] ?? 0);
                // A transaction cannot be its own parent, which a file that
                // repeats the txn_id in parent_txn_id would otherwise ask for.
                if ($candidate > 0 && $candidate !== $entry['id']) {
                    $parentId = $candidate;
                    $linked++;
                }
            }

            // created_at only on the create path: on an existing transaction the
            // stored date is the real one.
            $createdAt = $entry['created'] ? (string)($data['created_at'] ?? '') : '';

            $this->extraData->fixImportedRow($entry['id'], $createdAt, $parentId);
        }
        return $linked;
    }

    /**
     * Copy every table-backed column onto the transaction.
     *
     * Empty cells are skipped, so a nullable column stays NULL rather than
     * becoming an empty string. `created_at` is copied too, but the model
     * overwrites it — see {@see fixSavedRows()}.
     *
     * @param array<string, string> $data
     */
    private function applyMappedFields(Transaction $transaction, array $data): void
    {
        foreach ($this->schema->getFields() as $column => $field) {
            $value = $data[$column] ?? '';
            if ($value === '') {
                continue;
            }
            $transaction->setData($field, $this->schema->isNumericColumn($column) ? (float)$value : $value);
        }
    }

    /**
     * The gateway payload as the resource model expects it: an array, which it
     * serialises on save.
     *
     * @param array<string, string> $data
     * @return array<string, mixed>|null null when the cell is empty or unusable,
     *         which leaves the column NULL rather than writing an empty payload
     */
    private function decodeAdditionalInformation(array $data): ?array
    {
        $json = (string)($data['additional_information'] ?? '');
        if ($json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }
}
