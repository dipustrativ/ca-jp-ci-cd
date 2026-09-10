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
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterfaceFactory;
use Magento\Sales\Api\Data\OrderItemInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\AddressFactory;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\PaymentFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Applies a validated order CSV: upserts orders by increment_id.
 *
 *  - existing increment_id  -> UPDATE (status / state + a history comment, plus a
 *                              product re-link for items that have none; financials
 *                              on placed orders are intentionally left untouched)
 *  - new increment_id       -> CREATE a new order straight from the CSV values
 *
 * The create path trusts the CSV totals verbatim — it does NOT rebuild a quote,
 * recalculate totals, or decrement stock. It is a data round-trip / migration
 * tool, not a checkout. Nothing is written unless validation passes first.
 */
class Importer
{
    /** @var array<string, int|null> sku => product id (per-run cache) */
    private array $productIdCache = [];

    public function __construct(
        private readonly Validator $validator,
        private readonly Schema $schema,
        private readonly OrderInterfaceFactory $orderFactory,
        private readonly OrderItemInterfaceFactory $orderItemFactory,
        private readonly AddressFactory $addressFactory,
        private readonly PaymentFactory $paymentFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductRepositoryInterface $productRepository,
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
     *     relinked: array<string, int>,
     *     failed: array<string, string>
     * }
     */
    public function import(array $parsed): array
    {
        $this->productIdCache = [];
        $validation = $this->validator->validate($parsed);

        if (!$validation->isValid()) {
            $this->logger->warning('Import aborted — validation failed with ' . $validation->getSummary()['errors'] . ' error(s).');
            return [
                'success' => false,
                'validation' => $validation,
                'created' => [],
                'updated' => [],
                'relinked' => [],
                'failed' => [],
            ];
        }

        // Group rows by increment_id, preserving first-seen order.
        $grouped = [];
        foreach ($parsed['rows'] as $row) {
            $inc = $row['data']['increment_id'];
            $grouped[$inc][] = $row['data'];
        }

        $plan = $validation->getPlan();
        $created = [];
        $updated = [];
        $relinked = [];
        $failed = [];

        foreach ($grouped as $incrementId => $itemRows) {
            $mode = $plan[(string)$incrementId]['mode'] ?? 'create';
            try {
                if ($mode === 'update') {
                    $count = $this->updateOrder((string)$incrementId, $itemRows);
                    $updated[] = (string)$incrementId;
                    if ($count > 0) {
                        $relinked[(string)$incrementId] = $count;
                    }
                } else {
                    $this->createOrder((string)$incrementId, $itemRows);
                    $created[] = (string)$incrementId;
                }
            } catch (\Throwable $e) {
                $failed[(string)$incrementId] = $e->getMessage();
                $this->logger->error(sprintf(
                    'Import failed for order %s (%s): %s',
                    $incrementId,
                    $mode,
                    $e->getMessage()
                ));
            }
        }

        $this->logger->info(sprintf(
            'Import finished: %d created, %d updated, %d failed, %d item(s) re-linked to a product.',
            count($created),
            count($updated),
            count($failed),
            array_sum($relinked)
        ));

        return [
            'success' => true,
            'validation' => $validation,
            'created' => $created,
            'updated' => $updated,
            'relinked' => $relinked,
            'failed' => $failed,
        ];
    }

    /**
     * Update an existing order: status/state + an audit comment, and re-link any
     * item that still has no product. Financials are deliberately not modified —
     * a placed order's totals must not be rewritten.
     *
     * @param array<int, array<string, string>> $itemRows every CSV row for this order
     * @return int how many items gained a product link
     */
    private function updateOrder(string $incrementId, array $itemRows): int
    {
        $data = $itemRows[0];

        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('increment_id', $incrementId)->setPageSize(1);
        /** @var Order|null $order */
        $order = $collection->getFirstItem();
        if (!$order || !$order->getId()) {
            throw new NoSuchEntityException(__('Order %1 disappeared before update.', $incrementId));
        }

        $status = $data['order_status'] ?? '';
        $state = $data['order_state'] ?? '';

        if ($state !== '') {
            $order->setState($state);
        }
        if ($status !== '') {
            $order->setStatus($status);
            $order->addCommentToStatusHistory(
                (string)__('Status set to "%1" via ExportImport CSV import.', $status),
                $status
            );
        }

        $relinked = $this->relinkItemProducts($order, $itemRows);

        $this->orderRepository->save($order);

        return $relinked;
    }

    /**
     * Give a product link to items that have none.
     *
     * An item imported while its SKU was absent from this catalog is stored with
     * `product_id = NULL` — the item keeps its SKU, name and prices, but nothing
     * points at a product row. Once the product exists (a later catalog import,
     * say), re-importing the same order fills the link in.
     *
     * Scope is deliberately narrow: an item is touched only when it currently has
     * NO product id AND its SKU appears in the CSV being imported AND that SKU now
     * resolves. An item that already points at a product is never re-pointed —
     * re-linking a live order to a different product row is not something a CSV
     * re-import should be able to do.
     *
     * Persistence: `Magento\Sales\Model\ResourceModel\Order\Relation::processRelation()`
     * saves every item of the order on `orderRepository->save()`, so mutating the
     * loaded items here is enough — no separate item save.
     *
     * Known limitation: matching is by SKU string. A SKU later reused for a
     * different product links the item to that product. `product_options` is left
     * alone either way, so a re-linked item keeps the option ids it was bought
     * with even if the new product's options differ.
     *
     * @param Order $order
     * @param array<int, array<string, string>> $itemRows
     * @return int
     */
    private function relinkItemProducts(Order $order, array $itemRows): int
    {
        $csvSkus = [];
        foreach ($itemRows as $row) {
            $sku = $row['item_sku'] ?? '';
            if ($sku !== '') {
                $csvSkus[$sku] = $row;
            }
        }
        if (empty($csvSkus)) {
            return 0;
        }

        $relinked = 0;
        foreach ($order->getAllItems() as $item) {
            if ((int)$item->getProductId() > 0) {
                continue;
            }

            $sku = (string)$item->getSku();
            if ($sku === '' || !isset($csvSkus[$sku])) {
                continue;
            }

            $productId = $this->resolveProductId($sku);
            if ($productId === null) {
                continue;
            }

            $item->setProductId($productId);
            if ((string)$item->getProductType() === '') {
                $item->setProductType(($csvSkus[$sku]['item_product_type'] ?? '') ?: 'simple');
            }
            $relinked++;
        }

        if ($relinked > 0) {
            // Audit trail in the admin order view. Pass the current status so the
            // comment does not move the order off it.
            $order->addCommentToStatusHistory(
                (string)__(
                    '%1 order item(s) linked to their catalog product via ExportImport CSV import.',
                    $relinked
                ),
                $order->getStatus()
            );
            $this->logger->info(sprintf(
                'Order %s: re-linked %d item(s) to a catalog product.',
                (string)$order->getIncrementId(),
                $relinked
            ));
        }

        return $relinked;
    }

    /**
     * Create a brand-new order from CSV rows (all rows share one increment_id).
     *
     * Every column with a backing field in Schema::ORDER_COLUMN_MAP is copied
     * through generically; what remains below is the handful of decisions the
     * map cannot express (store resolution, currency fallbacks, item parenting,
     * whether an address is present at all).
     *
     * @param array<int, array<string, string>> $itemRows
     */
    private function createOrder(string $incrementId, array $itemRows): void
    {
        $head = $itemRows[0];
        $storeId = (int)$this->storeManager->getStore($head['store_code'] ?? '')->getId();

        /** @var Order $order */
        $order = $this->orderFactory->create();
        $order->setStoreId($storeId);
        $order->setIncrementId($incrementId);

        // Straight copy of every mapped sales_order column.
        $this->applyMappedFields($order, $head, 'order');

        // Currency needs fallbacks: an order must have one even if the CSV omits it.
        $orderCurrency = ($head['order_currency_code'] ?? '') ?: 'JPY';
        $baseCurrency = ($head['base_currency_code'] ?? '') ?: $orderCurrency;
        $order->setOrderCurrencyCode($orderCurrency);
        $order->setBaseCurrencyCode($baseCurrency);
        $order->setGlobalCurrencyCode(($head['global_currency_code'] ?? '') ?: $baseCurrency);
        $order->setStoreCurrencyCode(($head['store_currency_code'] ?? '') ?: $orderCurrency);

        if (!empty($head['created_at'])) {
            $order->setCreatedAt($head['created_at']);
        }

        // State / status.
        $order->setState(($head['order_state'] ?? '') ?: Order::STATE_NEW);
        $order->setStatus(($head['order_status'] ?? '') ?: 'pending');

        // Items. Keep a sku => item map so child lines can be linked to their parent.
        $itemsBySku = [];
        foreach ($itemRows as $row) {
            $item = $this->buildItem($storeId, $row);
            $order->addItem($item);
            $sku = $row['item_sku'] ?? '';
            if ($sku !== '') {
                $itemsBySku[$sku] = $item;
            }
        }
        foreach ($itemRows as $row) {
            $parentSku = $row['item_parent_sku'] ?? '';
            $sku = $row['item_sku'] ?? '';
            if ($parentSku !== '' && isset($itemsBySku[$parentSku], $itemsBySku[$sku])) {
                $itemsBySku[$sku]->setParentItem($itemsBySku[$parentSku]);
            }
        }

        // Status history / comments (packed JSON). Tax details are export-only.
        $this->applyStatusHistory($order, $head['order_status_history'] ?? '');

        // Order::addCommentToStatusHistory() calls setStatus() for every entry it
        // adds, so replaying the history leaves the order on whichever status the
        // last history row carried. Re-assert the CSV's status/state afterwards —
        // otherwise a closed order imports as state=closed, status=pending.
        $order->setState(($head['order_state'] ?? '') ?: Order::STATE_NEW);
        $order->setStatus(($head['order_status'] ?? '') ?: 'pending');

        // Addresses.
        $billing = $this->buildAddress($head, 'billing');
        if ($billing !== null) {
            $order->setBillingAddress($billing);
        }
        $shipping = $this->buildAddress($head, 'shipping');
        if ($shipping !== null) {
            $order->setShippingAddress($shipping);
        } else {
            $order->setIsVirtual(1);
        }

        // Payment. Amounts/references are taken verbatim; the gateway transaction
        // log (`sales_payment_transaction`) is export-only — replaying gateway
        // transactions locally would fabricate payment records that never happened.
        $payment = $this->paymentFactory->create();
        $this->applyMappedFields($payment, $head, 'payment');
        $payment->setMethod(($head['payment_method'] ?? '') ?: 'checkmo');
        $this->applyAdditionalInformation($payment, $head['payment_additional_information'] ?? '');
        $order->setPayment($payment);

        $this->orderRepository->save($order);
    }

    /**
     * Copy every non-computed column of one source onto a record.
     *
     * Empty cells are skipped, except for the columns Schema marks as
     * zero-defaulting — that keeps a nullable DB column NULL instead of writing
     * 0.0000 into it, while preserving the historic behaviour for the core
     * totals that older CSVs may omit.
     *
     * @param array<string, string> $data
     * @param string $source order|payment|billing|shipping|item
     */
    private function applyMappedFields(\Magento\Framework\DataObject $target, array $data, string $source): void
    {
        foreach ($this->schema->getFieldsFor($source) as $column => $field) {
            if ($this->schema->isExportOnly($column)) {
                continue;
            }

            $value = $data[$column] ?? '';
            $numeric = $this->schema->isNumericColumn($column);

            if ($value === '') {
                if ($numeric && $this->schema->hasZeroDefault($column)) {
                    $target->setData($field, 0.0);
                }
                continue;
            }

            $target->setData($field, $numeric ? (float)$value : $value);
        }
    }

    /**
     * Restore the payment's additional_information map from its JSON cell.
     */
    private function applyAdditionalInformation(
        \Magento\Sales\Model\Order\Payment $payment,
        string $json
    ): void {
        if ($json === '') {
            return;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return;
        }
        foreach ($decoded as $key => $value) {
            $payment->setAdditionalInformation((string)$key, $value);
        }
    }

    /**
     * Recreate status-history entries from the packed JSON column.
     */
    private function applyStatusHistory(Order $order, string $json): void
    {
        if ($json === '') {
            return;
        }
        $entries = json_decode($json, true);
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $comment = (string)($entry['comment'] ?? '');
            $status = (string)($entry['status'] ?? $order->getStatus());
            $history = $order->addCommentToStatusHistory($comment, $status, (bool)($entry['is_visible_on_front'] ?? 0));
            $history->setIsCustomerNotified((int)($entry['is_customer_notified'] ?? 0));
            if (!empty($entry['created_at'])) {
                $history->setCreatedAt((string)$entry['created_at']);
            }
        }
    }

    /**
     * Build one order item from a CSV row.
     *
     * @param array<string, string> $row
     */
    private function buildItem(int $storeId, array $row): OrderItem
    {
        /** @var OrderItem $item */
        $item = $this->orderItemFactory->create();
        $item->setStoreId($storeId);

        // Straight copy of every mapped sales_order_item column.
        $this->applyMappedFields($item, $row, 'item');

        // Product is resolved from the SKU against THIS instance, never from an
        // exported id — see the module README.
        $item->setProductId($this->resolveProductId($row['item_sku'] ?? ''));
        $item->setProductType(($row['item_product_type'] ?? '') ?: 'simple');

        // product_options is an array on the model and JSON in the column: Magento
        // serializes it on save. Handing it back the exported JSON *string* would
        // store a JSON-encoded string instead of the option structure, so decode it.
        $options = $row['item_product_options'] ?? '';
        if ($options !== '') {
            $decoded = json_decode($options, true);
            $item->setProductOptions(is_array($decoded) ? $decoded : []);
        }

        // Original Price is a distinct figure from Price (it is the
        // pre-special-price catalog value). Fall back to Price only when the
        // column is absent, so an older CSV still imports, without flattening a
        // discounted line's Original Price down to what was actually charged.
        if (($row['item_original_price'] ?? '') === '') {
            $item->setOriginalPrice($this->float($row['item_price'] ?? ''));
        }
        if (($row['item_base_original_price'] ?? '') === '') {
            $item->setBaseOriginalPrice($this->float($row['item_base_price'] ?? ''));
        }

        return $item;
    }

    /**
     * Build one order address, or null when the CSV carries no address of that type.
     *
     * @param array<string, string> $head
     */
    private function buildAddress(array $head, string $type): ?\Magento\Sales\Model\Order\Address
    {
        $prefix = $type . '_';
        // Require at least a street or city to consider the address present.
        if (($head[$prefix . 'street'] ?? '') === '' && ($head[$prefix . 'city'] ?? '') === '') {
            return null;
        }

        $address = $this->addressFactory->create();
        $address->setAddressType($type);

        // Straight copy of every mapped sales_order_address column.
        $this->applyMappedFields($address, $head, $type);

        $address->setStreet($this->streetToArray($head[$prefix . 'street'] ?? ''));
        $address->setEmail($head['customer_email'] ?? '');
        return $address;
    }

    private function resolveProductId(string $sku): ?int
    {
        if ($sku === '') {
            return null;
        }
        if (array_key_exists($sku, $this->productIdCache)) {
            return $this->productIdCache[$sku];
        }
        try {
            return $this->productIdCache[$sku] = (int)$this->productRepository->get($sku)->getId();
        } catch (NoSuchEntityException $e) {
            return $this->productIdCache[$sku] = null;
        }
    }

    /** @return string[] */
    private function streetToArray(string $street): array
    {
        if ($street === '') {
            return [];
        }
        return array_map('trim', explode('|', $street));
    }

    private function float(string $value): float
    {
        return $value === '' ? 0.0 : (float)$value;
    }
}
