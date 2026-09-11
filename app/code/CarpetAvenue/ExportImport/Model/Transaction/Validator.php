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
use CarpetAvenue\ExportImport\Model\Order\ValidationResultFactory;
use CarpetAvenue\ExportImport\Model\ResourceModel\DocumentExtraData;
use CarpetAvenue\ExportImport\Model\ResourceModel\TransactionExtraData;
use Magento\Sales\Api\Data\TransactionInterface;

/**
 * Validates a parsed payment-transaction CSV BEFORE any data is written — the
 * "manual check" gate, and the transaction counterpart of
 * {@see \CarpetAvenue\ExportImport\Model\Document\Validator}.
 *
 * Errors block the import; warnings (a parent transaction that is not in the
 * file and not in the database, say) do not.
 *
 * Three things are errors here that have no equivalent on the document side:
 *
 *  - **A missing order**, for the same reason as a document: a transaction with
 *    no order is a record of nothing and `order_id` is NOT NULL.
 *  - **An order with no payment row.** `sales_payment_transaction.payment_id` is
 *    NOT NULL and is taken from the order's `sales_order_payment`. Without one
 *    there is nothing to point at.
 *  - **An unknown `txn_type`.** Magento's own code switches on the six types in
 *    {@see TransactionInterface}; a seventh value would sit in the table
 *    unreadable by the admin grid and by every gateway. It is rejected at the
 *    gate rather than by `Transaction::setTxnType()` mid-import.
 */
class Validator
{
    /** The transaction types Magento recognises. */
    private const KNOWN_TYPES = [
        TransactionInterface::TYPE_PAYMENT,
        TransactionInterface::TYPE_ORDER,
        TransactionInterface::TYPE_AUTH,
        TransactionInterface::TYPE_CAPTURE,
        TransactionInterface::TYPE_VOID,
        TransactionInterface::TYPE_REFUND,
    ];

    public function __construct(
        private readonly Schema $schema,
        private readonly ValidationResultFactory $resultFactory,
        private readonly DocumentExtraData $orderData,
        private readonly TransactionExtraData $extraData,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array{header: string[], rows: array<int, array{line: int, data: array<string, string>}>} $parsed
     */
    public function validate(array $parsed): ValidationResult
    {
        $result = $this->resultFactory->create();

        $header = $parsed['header'] ?? [];
        $rows = $parsed['rows'] ?? [];

        // 1. Header must carry every required column — otherwise nothing can proceed.
        $missing = array_diff($this->schema->getRequiredColumns(), $header);
        if (!empty($missing)) {
            foreach ($missing as $column) {
                $result->addError(1, $column, (string)__('Required column is missing from the CSV header.'));
            }
            return $result;
        }

        if (empty($rows)) {
            $result->addError(1, 'file', (string)__('The CSV contains no data rows.'));
            return $result;
        }

        // Three batched lookups for the whole file: which orders exist, which of
        // them have a payment row, and which transactions they already have.
        $orderIds = $this->orderData->resolveOrderIds(
            array_map(static fn ($row) => (string)($row['data']['order_increment_id'] ?? ''), $rows)
        );
        $paymentIds = $this->extraData->getOrderPaymentIds(array_values($orderIds));
        $existing = $this->extraData->getTransactionIds(array_values($orderIds));
        $numericColumns = array_intersect($this->schema->getNumericColumns(), $header);
        $fileTxnIds = $this->fileTxnIdsByOrder($rows);

        /** @var array<string, int> row key => the line that first claimed it */
        $seen = [];

        foreach ($rows as $row) {
            $line = $row['line'];
            $data = $row['data'];

            $txnId = $data['txn_id'] ?? '';
            if ($txnId === '') {
                $result->addError($line, 'txn_id', (string)__('txn_id is required.'));
                continue;
            }

            $orderIncrementId = $data['order_increment_id'] ?? '';
            $orderId = $orderIds[$orderIncrementId] ?? null;
            if ($orderIncrementId === '') {
                $result->addError($line, 'order_increment_id', (string)__('order_increment_id is required.'));
            } elseif ($orderId === null) {
                $result->addError(
                    $line,
                    'order_increment_id',
                    (string)__(
                        'Order "%1" does not exist on this instance. The transaction cannot be imported without '
                        . 'its order — import the orders first.',
                        $orderIncrementId
                    )
                );
            } elseif (!isset($paymentIds[$orderId])) {
                $result->addError(
                    $line,
                    'order_increment_id',
                    (string)__(
                        'Order "%1" has no payment record, so a transaction cannot be attached to it. Re-import '
                        . 'the order with its payment columns first.',
                        $orderIncrementId
                    )
                );
            }

            $txnType = $data['txn_type'] ?? '';
            if ($txnType === '') {
                $result->addError($line, 'txn_type', (string)__('txn_type is required.'));
            } elseif (!in_array($txnType, self::KNOWN_TYPES, true)) {
                $result->addError(
                    $line,
                    'txn_type',
                    (string)__(
                        'Unknown txn_type "%1". Magento recognises: %2.',
                        $txnType,
                        implode(', ', self::KNOWN_TYPES)
                    )
                );
            }

            // The table's unique key is (order, payment, txn_id), so two rows
            // claiming the same pair would collide on insert.
            $key = $this->schema->rowKey($data);
            if (isset($seen[$key])) {
                $result->addError(
                    $line,
                    'txn_id',
                    (string)__(
                        'txn_id "%1" appears twice for order "%2" (first on line %3). One transaction per order '
                        . 'and txn_id.',
                        $txnId,
                        $orderIncrementId,
                        $seen[$key]
                    )
                );
                continue;
            }
            $seen[$key] = $line;

            $parentTxnId = $data['parent_txn_id'] ?? '';
            if ($parentTxnId !== '' && $orderId !== null) {
                $inFile = isset($fileTxnIds[$orderIncrementId][$parentTxnId]);
                $inDb = isset($existing[$orderId][$parentTxnId]);
                if (!$inFile && !$inDb) {
                    $result->addWarning(
                        $line,
                        'parent_txn_id',
                        (string)__(
                            'Parent transaction "%1" is neither in this file nor already on order "%2". The '
                            . 'transaction will be imported as a root transaction, without the parent link.',
                            $parentTxnId,
                            $orderIncrementId
                        )
                    );
                }
            }

            foreach ($numericColumns as $column) {
                $value = $data[$column] ?? '';
                if ($value !== '' && !is_numeric($value)) {
                    $result->addError($line, $column, (string)__('"%1" must be numeric, got "%2".', $column, $value));
                }
            }

            // JSON-packed columns: malformed JSON is a warning, not a hard error
            // (the row still imports, just without that packed data).
            foreach ($this->schema->getJsonColumns() as $jsonColumn) {
                $value = $data[$jsonColumn] ?? '';
                if ($value !== '' && json_decode($value, true) === null && json_last_error() !== JSON_ERROR_NONE) {
                    $result->addWarning(
                        $line,
                        $jsonColumn,
                        (string)__('"%1" is not valid JSON — it will be ignored on import.', $jsonColumn)
                    );
                }
            }

            if (($data['created_at'] ?? '') === '') {
                $result->addWarning(
                    $line,
                    'created_at',
                    (string)__(
                        'created_at is empty. The transaction will be stamped with the time of the import '
                        . 'instead of its original date.'
                    )
                );
            }

            $mode = ($orderId !== null && isset($existing[$orderId][$txnId])) ? 'update' : 'create';
            $result->registerOrder($key, $mode, $line);
        }

        $summary = $result->getSummary();
        $this->logger->info(sprintf(
            'Validated transaction CSV: %d transaction(s) [%d create / %d update], %d error(s), %d warning(s).',
            $summary['orders'],
            $summary['creates'],
            $summary['updates'],
            $summary['errors'],
            $summary['warnings']
        ));

        return $result;
    }

    /**
     * Every txn_id the file itself carries, per order — so a parent that arrives
     * later in the same file is not reported as missing.
     *
     * @param array<int, array{line: int, data: array<string, string>}> $rows
     * @return array<string, array<string, bool>> order increment id => txn_id => true
     */
    private function fileTxnIdsByOrder(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $orderIncrementId = (string)($row['data']['order_increment_id'] ?? '');
            $txnId = (string)($row['data']['txn_id'] ?? '');
            if ($orderIncrementId !== '' && $txnId !== '') {
                $map[$orderIncrementId][$txnId] = true;
            }
        }
        return $map;
    }
}
