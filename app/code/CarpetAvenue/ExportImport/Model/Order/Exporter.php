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

namespace CarpetAvenue\ExportImport\Model\Order;

use CarpetAvenue\Core\Logger\Logger;
use CarpetAvenue\ExportImport\Model\Product\DefaultNameProvider;
use CarpetAvenue\ExportImport\Model\ResourceModel\OrderExtraData;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\DataObject;
use Magento\Framework\Filesystem;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory as TransactionCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Api\OrderTaxManagementInterface;

/**
 * Builds a store-wise order CSV (one row per order item) from a set of filters.
 *
 * Filters (all optional):
 *   store_id  int      restrict to one store view
 *   status    string[] restrict to these order status codes
 *   from      string   created_at >= "Y-m-d" (inclusive)
 *   to        string   created_at <= "Y-m-d" (inclusive, whole day)
 */
class Exporter
{
    /** Relative sub-directory under var/ where CSVs are written. */
    private const EXPORT_DIR = 'export/carpetavenue_orders';

    public function __construct(
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Schema $schema,
        private readonly Filesystem $filesystem,
        private readonly OrderTaxManagementInterface $orderTaxManagement,
        private readonly OrderExtraData $orderExtraData,
        private readonly DefaultNameProvider $defaultNameProvider,
        private readonly TransactionCollectionFactory $transactionCollectionFactory,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Export matching orders to a CSV file under var/.
     *
     * @param array $filters
     * @return array{relative_path: string, absolute_path: string, orders: int, rows: int}
     */
    public function exportToFile(array $filters, ?string $fileName = null): array
    {
        $fileName ??= 'orders_export_' . date('Ymd_His') . '.csv';
        $relativePath = self::EXPORT_DIR . '/' . $fileName;

        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $directory->create(self::EXPORT_DIR);

        $stream = $directory->openFile($relativePath, 'w+');
        $orderCount = 0;
        $rowCount = 0;
        // Fresh catalog-name lookups per run, so a rename since the last export shows up.
        $this->defaultNameProvider->reset();

        try {
            $stream->lock();
            $stream->writeCsv($this->schema->getHeader());

            $collection = $this->buildCollection($filters);
            foreach ($collection as $order) {
                $orderCount++;
                foreach ($this->buildOrderRows($order) as $row) {
                    $stream->writeCsv($row);
                    $rowCount++;
                }
            }
        } finally {
            $stream->unlock();
            $stream->close();
        }

        $this->logger->info(sprintf(
            'Exported %d order(s), %d row(s) to %s (filters: %s)',
            $orderCount,
            $rowCount,
            $relativePath,
            json_encode($filters)
        ));

        return [
            'relative_path' => $relativePath,
            'absolute_path' => $directory->getAbsolutePath($relativePath),
            'orders' => $orderCount,
            'rows' => $rowCount,
        ];
    }

    /**
     * @param array $filters
     * @return \Magento\Sales\Model\ResourceModel\Order\Collection
     */
    private function buildCollection(array $filters)
    {
        $collection = $this->orderCollectionFactory->create();

        if (!empty($filters['store_id'])) {
            $collection->addFieldToFilter('store_id', (int)$filters['store_id']);
        }
        if (!empty($filters['status'])) {
            $statuses = (array)$filters['status'];
            $collection->addFieldToFilter('status', ['in' => $statuses]);
        }
        if (!empty($filters['from'])) {
            $collection->addFieldToFilter('created_at', ['gteq' => $filters['from'] . ' 00:00:00']);
        }
        if (!empty($filters['to'])) {
            $collection->addFieldToFilter('created_at', ['lteq' => $filters['to'] . ' 23:59:59']);
        }

        $collection->setOrder('created_at', 'DESC');
        return $collection;
    }

    /**
     * @param Order $order
     * @return array<int, array<string, string>>
     */
    private function buildOrderRows(Order $order): array
    {
        // sales_order_tax_item rows for the whole order, fetched once and split per item.
        $taxItems = $this->orderExtraData->getTaxItems((int)$order->getEntityId());
        $orderData = $this->mapOrderColumns($order, $taxItems);
        $rows = [];

        // getAllItems (not visible-only) so configurable/bundle child lines export too,
        // linked back to their parent via item_parent_sku.
        $items = $order->getAllItems();
        if (empty($items)) {
            // Order with no visible items still gets one row so it round-trips.
            $rows[] = $this->flatten(array_merge($orderData, $this->emptyItemColumns()));
            return $rows;
        }

        // One batched catalog lookup for this order's SKUs (cached across orders).
        $defaultNames = $this->defaultNameProvider->getBySkus(
            array_map(static fn ($item) => (string)$item->getSku(), $items)
        );

        foreach ($items as $item) {
            $rows[] = $this->flatten(
                array_merge($orderData, $this->mapItemColumns($item, $taxItems, $defaultNames))
            );
        }
        return $rows;
    }

    /**
     * Order-level columns for one order.
     *
     * Everything with a declared backing field is copied straight through from
     * Schema::ORDER_COLUMN_MAP; only the `computed` columns need code.
     *
     * @param array<string, array<int, array<string, mixed>>> $taxItems keyed by order item id,
     *        with the item-less rows (shipping tax, etc.) under the '' key
     * @return array<string, string>
     */
    private function mapOrderColumns(Order $order, array $taxItems = []): array
    {
        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();
        $payment = $order->getPayment();

        $row = [];
        $row += $this->copyFields('order', $order);
        $row += $this->copyFields('payment', $payment instanceof DataObject ? $payment : null);
        $row += $this->copyFields('billing', $billing);
        $row += $this->copyFields('shipping', $shipping);

        $sources = $this->orderExtraData->getAllocatedSources((int)$order->getEntityId());

        // The computed columns.
        $row['store_code'] = $this->resolveStoreCode($order);
        $row['billing_street'] = $this->streetToString($billing);
        $row['shipping_street'] = $this->streetToString($shipping);
        $row['payment_additional_information'] = $this->encodeAdditionalInformation($payment);
        $row['allocated_sources'] = implode(', ', $sources['names']);
        $row['allocated_source_codes'] = implode(',', $sources['codes']);
        $row['order_status_history'] = $this->encodeStatusHistory($order);
        $row['order_status_change_history'] = $this->encodeStatusChangeHistory($order);
        $row['order_tax_details'] = $this->encodeTaxDetails($order);
        // sales_order_tax_item rows with no item_id — shipping tax and the like.
        // They belong to no CSV row's item columns, so they ride at order level.
        $row['order_level_tax_details'] = ($taxItems[''] ?? []) === []
            ? ''
            : (string)json_encode($taxItems['']);
        $row['payment_transactions'] = $this->encodePaymentTransactions($order);

        return $row;
    }

    /**
     * Every column of one source, read straight off the record.
     *
     * A missing record (no shipping address on a virtual order, say) yields empty
     * cells rather than a hole in the row.
     *
     * @param string $source order|payment|billing|shipping|item
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

    private function resolveStoreCode(Order $order): string
    {
        try {
            return (string)$this->storeManager->getStore($order->getStoreId())->getCode();
        } catch (\Throwable $e) {
            return (string)$order->getStoreId();
        }
    }

    /**
     * Item-level columns for one row.
     *
     * @param array<string, array<int, array<string, mixed>>> $taxItems keyed by order item id
     * @param array<string, string> $defaultNames sku => default-scope catalog name
     * @return array<string, string>
     */
    private function mapItemColumns(
        OrderItem $item,
        array $taxItems = [],
        array $defaultNames = []
    ): array {
        $row = $this->copyFields('item', $item);

        $sku = (string)$item->getSku();
        $parent = $item->getParentItem();
        $itemTaxes = $taxItems[(string)(int)$item->getItemId()] ?? [];

        $row['item_parent_sku'] = $parent ? (string)$parent->getSku() : '';
        $row['item_name_default'] = $defaultNames[$sku] ?? '';
        $row['item_tax_details'] = $itemTaxes === [] ? '' : (string)json_encode($itemTaxes);

        return $row;
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

    private function emptyItemColumns(): array
    {
        return array_fill_keys(array_keys(Schema::ITEM_COLUMN_MAP), '');
    }

    /**
     * Pack the order's status history / comments into one JSON cell.
     *
     * Written oldest-first. Magento's own history collection is sorted newest-first,
     * but the importer replays these entries in array order — so exporting them
     * descending would recreate the history backwards.
     */
    private function encodeStatusHistory(Order $order): string
    {
        $histories = $order->getStatusHistories();
        usort(
            $histories,
            static fn ($a, $b) => [$a->getCreatedAt(), (int)$a->getEntityId()]
                <=> [$b->getCreatedAt(), (int)$b->getEntityId()]
        );

        $entries = [];
        foreach ($histories as $history) {
            $entries[] = [
                'status' => (string)$history->getStatus(),
                'comment' => (string)$history->getComment(),
                'is_customer_notified' => (int)$history->getIsCustomerNotified(),
                'is_visible_on_front' => (int)$history->getIsVisibleOnFront(),
                'entity_name' => (string)$history->getEntityName(),
                'created_at' => (string)$history->getCreatedAt(),
            ];
        }
        return $entries === [] ? '' : (string)json_encode($entries);
    }

    /**
     * Pack `sales_order_status_change_history` (the status-change log written on
     * every status transition) into one JSON cell. This is a different table from
     * `sales_order_status_history`, which holds the admin-visible comments.
     */
    private function encodeStatusChangeHistory(Order $order): string
    {
        $entries = $this->orderExtraData->getStatusChangeHistory((int)$order->getEntityId());
        return $entries === [] ? '' : (string)json_encode($entries);
    }

    /**
     * Pack `sales_payment_transaction` rows (auth/capture/refund gateway
     * transactions) into one JSON cell.
     */
    private function encodePaymentTransactions(Order $order): string
    {
        $collection = $this->transactionCollectionFactory->create()
            ->addOrderIdFilter((int)$order->getEntityId());
        $collection->setOrder('transaction_id', 'ASC');

        $rows = [];
        foreach ($collection as $transaction) {
            $rows[] = [
                'txn_id' => (string)$transaction->getTxnId(),
                'parent_txn_id' => (string)$transaction->getParentTxnId(),
                'txn_type' => (string)$transaction->getTxnType(),
                'is_closed' => (int)$transaction->getIsClosed(),
                'additional_information' => $transaction->getAdditionalInformation() ?: [],
                'created_at' => (string)$transaction->getCreatedAt(),
            ];
        }
        return $rows === [] ? '' : (string)json_encode($rows);
    }

    /**
     * Pack the payment's `additional_information` array into one JSON cell.
     */
    private function encodeAdditionalInformation(?\Magento\Sales\Api\Data\OrderPaymentInterface $payment): string
    {
        if ($payment === null) {
            return '';
        }
        $info = $payment->getAdditionalInformation();
        if (!is_array($info) || $info === []) {
            return '';
        }
        return (string)json_encode($info);
    }

    /**
     * Pack the per-rate applied tax breakdown into one JSON cell.
     */
    private function encodeTaxDetails(Order $order): string
    {
        try {
            $details = $this->orderTaxManagement->getOrderTaxDetails((int)$order->getEntityId());
            $applied = $details->getAppliedTaxes();
        } catch (\Throwable $e) {
            return '';
        }

        $rows = [];
        foreach ($applied ?? [] as $tax) {
            $rows[] = [
                'code' => (string)$tax->getCode(),
                'title' => (string)$tax->getTitle(),
                'percent' => (float)$tax->getPercent(),
                'amount' => (float)$tax->getAmount(),
                'base_amount' => (float)$tax->getBaseAmount(),
            ];
        }
        return $rows === [] ? '' : (string)json_encode($rows);
    }

    private function streetToString(?Address $address): string
    {
        if ($address === null) {
            return '';
        }
        $street = $address->getStreet();
        if (is_array($street)) {
            return implode(' | ', array_filter($street));
        }
        return (string)$street;
    }

    /**
     * Order the associative row by the canonical header sequence.
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
