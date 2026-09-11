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

namespace CarpetAvenue\ExportImport\Model\Document;

use CarpetAvenue\Core\Logger\Logger;
use CarpetAvenue\ExportImport\Model\ResourceModel\DocumentExtraData;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\DataObject;
use Magento\Framework\Filesystem;
use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\Collection as CreditmemoCollection;
use Magento\Sales\Model\ResourceModel\Order\Invoice\Collection as InvoiceCollection;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Collection as ShipmentCollection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds a store-wise invoice, credit-memo or shipment CSV (one row per document
 * item) from a set of filters. Which one it builds is decided entirely by the
 * injected {@see TypeInterface} — see the virtualTypes in di.xml.
 *
 * Filters (all optional):
 *   store_id            int      restrict to one store view
 *   state               int[]    restrict to these numeric document states —
 *                                ignored for a type with no state column
 *                                ({@see TypeInterface::getStateField()})
 *   from                string   created_at >= "Y-m-d" (inclusive)
 *   to                  string   created_at <= "Y-m-d" (inclusive, whole day)
 *   order_increment_id  string[] restrict to the documents of these orders
 */
class Exporter
{
    /** Relative sub-directory under var/ where CSVs are written. */
    private const EXPORT_DIR = 'export/carpetavenue_documents';

    public function __construct(
        private readonly TypeInterface $type,
        private readonly Schema $schema,
        private readonly StoreManagerInterface $storeManager,
        private readonly Filesystem $filesystem,
        private readonly DocumentExtraData $extraData,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Export matching documents to a CSV file under var/.
     *
     * @param array $filters
     * @return array{relative_path: string, absolute_path: string, documents: int, rows: int}
     */
    public function exportToFile(array $filters, ?string $fileName = null): array
    {
        $fileName ??= $this->type->getFilePrefix() . '_export_' . date('Ymd_His') . '.csv';
        $relativePath = self::EXPORT_DIR . '/' . $fileName;

        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $directory->create(self::EXPORT_DIR);

        $stream = $directory->openFile($relativePath, 'w+');
        $documentCount = 0;
        $rowCount = 0;

        try {
            $stream->lock();
            $stream->writeCsv($this->schema->getHeader());

            foreach ($this->buildCollection($filters) as $document) {
                $documentCount++;
                foreach ($this->buildDocumentRows($document) as $row) {
                    $stream->writeCsv($row);
                    $rowCount++;
                }
            }
        } finally {
            $stream->unlock();
            $stream->close();
        }

        $this->logger->info(sprintf(
            'Exported %d %s(s), %d row(s) to %s (filters: %s)',
            $documentCount,
            $this->type->getCode(),
            $rowCount,
            $relativePath,
            json_encode($filters)
        ));

        return [
            'relative_path' => $relativePath,
            'absolute_path' => $directory->getAbsolutePath($relativePath),
            'documents' => $documentCount,
            'rows' => $rowCount,
        ];
    }

    /**
     * @param array $filters
     * @return InvoiceCollection|CreditmemoCollection|ShipmentCollection
     */
    private function buildCollection(array $filters)
    {
        $collection = $this->type->createCollection();
        $stateField = $this->type->getStateField();

        if (!empty($filters['store_id'])) {
            $collection->addFieldToFilter('store_id', (int)$filters['store_id']);
        }
        if ($stateField !== null && !empty($filters['state'])) {
            $collection->addFieldToFilter($stateField, ['in' => array_map('intval', (array)$filters['state'])]);
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
            // an empty export rather than every document.
            $orderIds = $this->extraData->resolveOrderIds((array)$filters['order_increment_id']);
            $collection->addFieldToFilter('order_id', ['in' => $orderIds === [] ? [0] : array_values($orderIds)]);
        }

        $collection->setOrder('created_at', 'DESC');
        return $collection;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function buildDocumentRows(AbstractModel $document): array
    {
        $documentData = $this->mapDocumentColumns($document);
        $rows = [];

        $items = $this->type->getItems($document);
        if ($items === []) {
            // A document with no items still gets one row so it round-trips.
            $rows[] = $this->flatten(array_merge($documentData, $this->emptyItemColumns()));
            return $rows;
        }

        foreach ($items as $item) {
            $rows[] = $this->flatten(array_merge($documentData, $this->mapItemColumns($item)));
        }
        return $rows;
    }

    /**
     * Document-level columns for one document.
     *
     * Everything with a declared backing field is copied straight through from
     * the type's document map; only the `computed` columns need code, and the
     * type-specific ones of those come from
     * {@see TypeInterface::exportExtraColumns()}.
     *
     * @return array<string, string>
     */
    private function mapDocumentColumns(AbstractModel $document): array
    {
        $row = $this->copyFields('document', $document);

        $row['order_increment_id'] = $this->extraData->getOrderIncrementId((int)$document->getData('order_id'));
        $row['store_code'] = $this->resolveStoreCode($document);

        $comments = $this->type->getComments($document);
        $row['document_comments'] = $comments === [] ? '' : (string)json_encode($comments);

        return $row + $this->type->exportExtraColumns($document);
    }

    /**
     * @return array<string, string>
     */
    private function mapItemColumns(AbstractModel $item): array
    {
        return $this->copyFields('item', $item);
    }

    /**
     * Every column of one source, read straight off the record.
     *
     * @param string $source document|item
     * @return array<string, string>
     */
    private function copyFields(string $source, ?DataObject $record): array
    {
        $row = [];
        foreach ($this->schema->getFieldsFor($source) as $column => $field) {
            $row[$column] = $record === null ? '' : $this->stringify($record->getData($field));
        }
        return $row;
    }

    private function resolveStoreCode(AbstractModel $document): string
    {
        $storeId = (int)$document->getData('store_id');
        try {
            return (string)$this->storeManager->getStore($storeId)->getCode();
        } catch (\Throwable $e) {
            return (string)$storeId;
        }
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

    /** @return array<string, string> */
    private function emptyItemColumns(): array
    {
        return array_fill_keys($this->schema->getItemColumns(), '');
    }

    /**
     * Order the associative row by the canonical header sequence.
     *
     * @param array<string, string> $row
     * @return array<int, string>
     */
    private function flatten(array $row): array
    {
        $ordered = [];
        foreach ($this->schema->getHeader() as $column) {
            $ordered[] = $row[$column] ?? '';
        }
        return $ordered;
    }
}
