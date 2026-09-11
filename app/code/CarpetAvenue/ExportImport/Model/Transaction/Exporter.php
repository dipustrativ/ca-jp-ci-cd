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
use CarpetAvenue\ExportImport\Model\ResourceModel\DocumentExtraData;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory;

/**
 * Builds a payment-transaction CSV (one row per transaction) from a set of
 * filters.
 *
 * Filters (all optional):
 *   store_id            int      restrict to the transactions of one store's orders
 *   txn_type            string[] restrict to these transaction types
 *   from                string   created_at >= "Y-m-d" (inclusive)
 *   to                  string   created_at <= "Y-m-d" (inclusive, whole day)
 *   order_increment_id  string[] restrict to the transactions of these orders
 *
 * `store_id` filters through a join on `sales_order`: the transaction table has
 * no store column of its own, but a transaction always belongs to exactly one
 * order and that order belongs to a store, so the filter is exact.
 *
 * `additional_information` is exported as the JSON of whatever Magento has
 * stored — the gateway's own response payload, unserialised by the resource
 * model on load and re-encoded here. It is copied, never inspected: what a
 * gateway puts in it varies by gateway, and the point of the file is to preserve
 * it as it is.
 */
class Exporter
{
    /** Relative sub-directory under var/ where CSVs are written. */
    private const EXPORT_DIR = 'export/carpetavenue_transactions';

    public function __construct(
        private readonly Schema $schema,
        private readonly CollectionFactory $collectionFactory,
        private readonly Filesystem $filesystem,
        private readonly DocumentExtraData $extraData,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Export matching transactions to a CSV file under var/.
     *
     * @param array $filters
     * @return array{relative_path: string, absolute_path: string, transactions: int, rows: int}
     */
    public function exportToFile(array $filters, ?string $fileName = null): array
    {
        $fileName ??= 'transactions_export_' . date('Ymd_His') . '.csv';
        $relativePath = self::EXPORT_DIR . '/' . $fileName;

        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $directory->create(self::EXPORT_DIR);

        $stream = $directory->openFile($relativePath, 'w+');
        $rowCount = 0;

        try {
            $stream->lock();
            $stream->writeCsv($this->schema->getHeader());

            foreach ($this->buildCollection($filters) as $transaction) {
                $stream->writeCsv($this->buildRow($transaction));
                $rowCount++;
            }
        } finally {
            $stream->unlock();
            $stream->close();
        }

        $this->logger->info(sprintf(
            'Exported %d transaction(s) to %s (filters: %s)',
            $rowCount,
            $relativePath,
            json_encode($filters)
        ));

        return [
            'relative_path' => $relativePath,
            'absolute_path' => $directory->getAbsolutePath($relativePath),
            'transactions' => $rowCount,
            'rows' => $rowCount,
        ];
    }

    /**
     * @param array $filters
     * @return \Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\Collection
     */
    private function buildCollection(array $filters)
    {
        $collection = $this->collectionFactory->create();

        if (!empty($filters['store_id'])) {
            // The collection's own filter, which joins sales_order for it.
            $collection->addStoreFilter((int)$filters['store_id']);
        }
        if (!empty($filters['txn_type'])) {
            $collection->addTxnTypeFilter(array_values((array)$filters['txn_type']));
        }
        if (!empty($filters['from'])) {
            $collection->addFieldToFilter('created_at', ['gteq' => $filters['from'] . ' 00:00:00']);
        }
        if (!empty($filters['to'])) {
            $collection->addFieldToFilter('created_at', ['lteq' => $filters['to'] . ' 23:59:59']);
        }
        if (!empty($filters['order_increment_id'])) {
            // Filtering happens on order_id, so translate the increment ids
            // first. An unknown one resolves to nothing, which correctly yields
            // an empty export rather than every transaction.
            $orderIds = $this->extraData->resolveOrderIds((array)$filters['order_increment_id']);
            $collection->addFieldToFilter('order_id', ['in' => $orderIds === [] ? [0] : array_values($orderIds)]);
        }

        // Parents before children, so a file imported top to bottom rebuilds the
        // hierarchy in order even before the importer's parent pass runs.
        $collection->setOrder('transaction_id', 'ASC');
        return $collection;
    }

    /**
     * @return array<int, string>
     */
    private function buildRow(Transaction $transaction): array
    {
        $row = [];
        foreach ($this->schema->getFields() as $column => $field) {
            $row[$column] = $this->stringify($transaction->getData($field));
        }

        $row['order_increment_id'] = $this->extraData->getOrderIncrementId((int)$transaction->getOrderId());

        // getData(), not getAdditionalInformation(): the getter substitutes an
        // empty array for a NULL column, which would export "[]" and import back
        // as an empty payload instead of no payload.
        $additional = $transaction->getData('additional_information');
        $row['additional_information'] = ($additional === null || $additional === '')
            ? ''
            : (string)json_encode($additional);

        $ordered = [];
        foreach ($this->schema->getHeader() as $column) {
            $ordered[] = $row[$column] ?? '';
        }
        return $ordered;
    }

    /**
     * A DB value as it should appear in a CSV cell.
     *
     * NULL becomes an empty cell, not "0" or "" — the importer relies on the
     * difference to leave a nullable column alone rather than writing a zero.
     */
    private function stringify(mixed $value): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return $value === null ? '' : (string)json_encode($value);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return (string)$value;
    }
}
