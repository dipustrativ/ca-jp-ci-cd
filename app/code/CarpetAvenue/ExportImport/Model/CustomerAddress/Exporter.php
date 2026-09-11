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

namespace CarpetAvenue\ExportImport\Model\CustomerAddress;

use CarpetAvenue\Core\Logger\Logger;
use CarpetAvenue\ExportImport\Model\ResourceModel\CustomerExtraData;
use Magento\Customer\Model\Address;
use Magento\Customer\Model\ResourceModel\Address\CollectionFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds a customer-address CSV (one row per address) from a set of filters.
 *
 * Filters (all optional):
 *   website_id       int      restrict to the addresses of one website's customers
 *   group_id         int[]    restrict to these customer groups
 *   from             string   the CUSTOMER's created_at >= "Y-m-d" (inclusive)
 *   to               string   the CUSTOMER's created_at <= "Y-m-d" (inclusive)
 *   customer_email   string[] restrict to these customers' addresses
 *
 * Every filter except `customer_email` is a filter on the **customer**, applied
 * through a join on `customer_entity`. That is deliberate: the addresses are
 * only useful next to the customers they belong to, so the same filters that
 * produced a customer CSV produce the matching address CSV.
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
        private readonly ResourceConnection $resourceConnection,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Export matching addresses to a CSV file under var/.
     *
     * @param array $filters
     * @return array{relative_path: string, absolute_path: string, addresses: int, rows: int}
     */
    public function exportToFile(array $filters, ?string $fileName = null): array
    {
        $fileName ??= 'customer_addresses_export_' . date('Ymd_His') . '.csv';
        $relativePath = self::EXPORT_DIR . '/' . $fileName;

        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $directory->create(self::EXPORT_DIR);

        $stream = $directory->openFile($relativePath, 'w+');
        $rowCount = 0;

        try {
            $stream->lock();
            $stream->writeCsv($this->schema->getHeader());

            $collection = $this->buildCollection($filters);
            $addresses = $collection->getItems();
            $customerKeys = $this->extraData->getCustomerKeys(
                array_map(static fn ($address) => (int)$address->getData('parent_id'), $addresses)
            );
            $defaults = $this->defaultAddressIds(array_keys($customerKeys));

            foreach ($addresses as $address) {
                $stream->writeCsv($this->buildRow($address, $customerKeys, $defaults));
                $rowCount++;
            }
        } finally {
            $stream->unlock();
            $stream->close();
        }

        $this->logger->info(sprintf(
            'Exported %d customer address(es) to %s (filters: %s)',
            $rowCount,
            $relativePath,
            json_encode($filters)
        ));

        return [
            'relative_path' => $relativePath,
            'absolute_path' => $directory->getAbsolutePath($relativePath),
            'addresses' => $rowCount,
            'rows' => $rowCount,
        ];
    }

    /**
     * @param array $filters
     * @return \Magento\Customer\Model\ResourceModel\Address\Collection
     */
    private function buildCollection(array $filters)
    {
        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect('*');

        $needsCustomer = !empty($filters['website_id'])
            || !empty($filters['group_id'])
            || !empty($filters['from'])
            || !empty($filters['to'])
            || !empty($filters['customer_email']);

        if ($needsCustomer) {
            $collection->getSelect()->joinInner(
                ['ca_export_customer' => $this->resourceConnection->getTableName('customer_entity')],
                'ca_export_customer.entity_id = e.parent_id',
                []
            );
        }
        if (!empty($filters['website_id'])) {
            $collection->getSelect()->where('ca_export_customer.website_id = ?', (int)$filters['website_id']);
        }
        if (!empty($filters['group_id'])) {
            $collection->getSelect()->where(
                'ca_export_customer.group_id IN (?)',
                array_map('intval', (array)$filters['group_id'])
            );
        }
        if (!empty($filters['from'])) {
            $collection->getSelect()->where('ca_export_customer.created_at >= ?', $filters['from'] . ' 00:00:00');
        }
        if (!empty($filters['to'])) {
            $collection->getSelect()->where('ca_export_customer.created_at <= ?', $filters['to'] . ' 23:59:59');
        }
        if (!empty($filters['customer_email'])) {
            $collection->getSelect()->where(
                'LOWER(ca_export_customer.email) IN (?)',
                array_map('strtolower', array_values((array)$filters['customer_email']))
            );
        }

        // Grouped per customer, oldest address first, so the file reads the way
        // the admin's address book does.
        //
        // Ordered on the select, not through `setOrder()`: this is an EAV
        // collection, and `addAttributeToSort()` silently does nothing for a
        // column that is not an EAV attribute — `parent_id` is a plain column of
        // `customer_address_entity`, so the sort would just never be applied.
        $collection->getSelect()->order('e.parent_id ASC')->order('e.entity_id ASC');
        return $collection;
    }

    /**
     * @param array<int, array{email: string, website_id: int}> $customerKeys
     * @param array<int, array{default_billing: int, default_shipping: int}> $defaults
     * @return array<int, string>
     */
    private function buildRow(Address $address, array $customerKeys, array $defaults): array
    {
        $row = [];
        foreach ($this->schema->getFields() as $column => $field) {
            $row[$column] = $this->stringify($address->getData($field));
        }

        $customerId = (int)$address->getData('parent_id');
        $key = $customerKeys[$customerId] ?? null;
        $row['customer_email'] = $key['email'] ?? '';
        $row['customer_website_code'] = $key === null ? '' : $this->websiteCode($key['website_id']);

        $addressId = (int)$address->getId();
        $row['is_default_billing'] = ($defaults[$customerId]['default_billing'] ?? 0) === $addressId ? '1' : '0';
        $row['is_default_shipping'] = ($defaults[$customerId]['default_shipping'] ?? 0) === $addressId ? '1' : '0';

        $row['extra_attributes'] = $this->extraAttributeJson($address);

        $ordered = [];
        foreach ($this->schema->getHeader() as $column) {
            $ordered[] = $row[$column] ?? '';
        }
        return $ordered;
    }

    /**
     * The `default_billing` / `default_shipping` of a set of customers.
     *
     * Read in one statement rather than from each address model: the flags live
     * on `customer_entity`, so asking the address for them would load a customer
     * per address.
     *
     * @param int[] $customerIds
     * @return array<int, array{default_billing: int, default_shipping: int}>
     */
    private function defaultAddressIds(array $customerIds): array
    {
        $customerIds = array_values(array_filter(array_map('intval', $customerIds)));
        if ($customerIds === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('customer_entity'),
                ['entity_id', 'default_billing', 'default_shipping']
            )
            ->where('entity_id IN (?)', $customerIds);

        $defaults = [];
        foreach ($connection->fetchAll($select) as $row) {
            $defaults[(int)$row['entity_id']] = [
                'default_billing' => (int)$row['default_billing'],
                'default_shipping' => (int)$row['default_shipping'],
            ];
        }
        return $defaults;
    }

    /**
     * Every EAV attribute of this install that the map does not name, as one
     * JSON object.
     */
    private function extraAttributeJson(Address $address): string
    {
        $values = [];
        foreach ($this->schema->getExtraAttributeCodes() as $code) {
            $value = $address->getData($code);
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
     * A DB value as it should appear in a CSV cell.
     *
     * `street` is the one array-valued column: Magento stores it newline-joined
     * and the model may hand back either, so it is normalised to newlines inside
     * the single cell rather than JSON-encoded.
     */
    private function stringify(mixed $value): string
    {
        if (is_array($value)) {
            return implode("\n", array_map('strval', $value));
        }
        if ($value === null) {
            return '';
        }
        if (is_object($value)) {
            return (string)json_encode($value);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return (string)$value;
    }
}
