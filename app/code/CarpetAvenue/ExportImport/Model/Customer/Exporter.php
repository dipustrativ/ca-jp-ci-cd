<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Customer
 * @package   CarpetAvenue_ExportImport
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\ExportImport\Model\Customer;

use CarpetAvenue\Core\Logger\Logger;
use CarpetAvenue\ExportImport\Model\ResourceModel\CustomerExtraData;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds a customer CSV (one row per customer) from a set of filters.
 *
 * Filters (all optional):
 *   website_id   int      restrict to the accounts of one website
 *   group_id     int[]    restrict to these customer groups
 *   from         string   created_at >= "Y-m-d" (inclusive)
 *   to           string   created_at <= "Y-m-d" (inclusive, whole day)
 *   email        string[] restrict to these email addresses
 *
 * The collection selects `*`, so every EAV attribute lands on the row and the
 * `extra_attributes` cell can be filled without a second query per customer.
 */
class Exporter
{
    /** Relative sub-directory under var/ where CSVs are written. */
    private const EXPORT_DIR = 'export/carpetavenue_customers';

    public function __construct(
        private readonly Schema $schema,
        private readonly CollectionFactory $collectionFactory,
        private readonly Filesystem $filesystem,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerExtraData $extraData,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Export matching customers to a CSV file under var/.
     *
     * @param array $filters
     * @return array{relative_path: string, absolute_path: string, customers: int, rows: int}
     */
    public function exportToFile(array $filters, ?string $fileName = null): array
    {
        $fileName ??= 'customers_export_' . date('Ymd_His') . '.csv';
        $relativePath = self::EXPORT_DIR . '/' . $fileName;

        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $directory->create(self::EXPORT_DIR);

        $stream = $directory->openFile($relativePath, 'w+');
        $rowCount = 0;

        try {
            $stream->lock();
            $stream->writeCsv($this->schema->getHeader());

            foreach ($this->buildCollection($filters) as $customer) {
                $stream->writeCsv($this->buildRow($customer));
                $rowCount++;
            }
        } finally {
            $stream->unlock();
            $stream->close();
        }

        $this->logger->info(sprintf(
            'Exported %d customer(s) to %s (filters: %s)',
            $rowCount,
            $relativePath,
            json_encode($filters)
        ));

        return [
            'relative_path' => $relativePath,
            'absolute_path' => $directory->getAbsolutePath($relativePath),
            'customers' => $rowCount,
            'rows' => $rowCount,
        ];
    }

    /**
     * @param array $filters
     * @return \Magento\Customer\Model\ResourceModel\Customer\Collection
     */
    private function buildCollection(array $filters)
    {
        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect('*');

        if (!empty($filters['website_id'])) {
            $collection->addFieldToFilter('website_id', (int)$filters['website_id']);
        }
        if (!empty($filters['group_id'])) {
            $collection->addFieldToFilter('group_id', ['in' => array_map('intval', (array)$filters['group_id'])]);
        }
        if (!empty($filters['from'])) {
            $collection->addFieldToFilter('created_at', ['gteq' => $filters['from'] . ' 00:00:00']);
        }
        if (!empty($filters['to'])) {
            $collection->addFieldToFilter('created_at', ['lteq' => $filters['to'] . ' 23:59:59']);
        }
        if (!empty($filters['email'])) {
            $collection->addFieldToFilter('email', ['in' => array_values((array)$filters['email'])]);
        }

        $collection->setOrder('entity_id', 'ASC');
        return $collection;
    }

    /**
     * @return array<int, string>
     */
    private function buildRow(Customer $customer): array
    {
        $row = [];
        foreach ($this->schema->getFields() as $column => $field) {
            $row[$column] = $this->stringify($customer->getData($field));
        }

        $row['website_code'] = $this->websiteCode((int)$customer->getData('website_id'));
        $row['store_code'] = $this->storeCode((int)$customer->getData('store_id'));
        $row['group_code'] = $this->extraData->getGroupCodes()[(int)$customer->getData('group_id')] ?? '';
        $row['extra_attributes'] = $this->extraAttributeJson($customer);

        $ordered = [];
        foreach ($this->schema->getHeader() as $column) {
            $ordered[] = $row[$column] ?? '';
        }
        return $ordered;
    }

    /**
     * Every EAV attribute of this install that the map does not name, as one
     * JSON object. Attributes with no value on this customer are left out, so
     * the cell stays empty for a customer that has none.
     */
    private function extraAttributeJson(Customer $customer): string
    {
        $values = [];
        foreach ($this->schema->getExtraAttributeCodes() as $code) {
            $value = $customer->getData($code);
            if ($value !== null && $value !== '') {
                $values[$code] = is_array($value) ? implode(',', $value) : (string)$value;
            }
        }
        return $values === [] ? '' : (string)json_encode($values);
    }

    private function websiteCode(int $websiteId): string
    {
        try {
            return (string)$this->storeManager->getWebsite($websiteId)->getCode();
        } catch (\Throwable $e) {
            return (string)$websiteId;
        }
    }

    /**
     * A customer's store view code. `store_id` 0 is the admin scope — a customer
     * created in the admin has it — and has no meaningful code, so it exports
     * empty and the importer falls back to the website's default store.
     */
    private function storeCode(int $storeId): string
    {
        if ($storeId <= 0) {
            return '';
        }
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
}
