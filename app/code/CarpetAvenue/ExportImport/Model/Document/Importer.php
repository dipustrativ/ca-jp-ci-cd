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
use CarpetAvenue\ExportImport\Model\ErrorMessage;
use CarpetAvenue\ExportImport\Model\Config;
use CarpetAvenue\ExportImport\Model\Order\StateGuard;
use CarpetAvenue\ExportImport\Model\Order\ValidationResult;
use CarpetAvenue\ExportImport\Model\ResourceModel\DocumentExtraData;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Applies a validated invoice / credit-memo / shipment CSV: upserts documents by
 * `increment_id` against orders that already exist here.
 *
 *  - new increment_id       -> CREATE the document from the CSV values, attached
 *                              to the order named by `order_increment_id`
 *  - existing increment_id  -> UPDATE (`state`, plus a link for items that have
 *                              none; amounts on an existing document are never
 *                              rewritten)
 *
 * Like the order importer, the create path trusts the CSV totals **verbatim**.
 * It does NOT go through `InvoiceService::prepareInvoice()` /
 * `CreditmemoFactory::createByOrder()` / `ShipmentFactory::create()`, does not
 * recalculate a single amount, does not touch stock, and sends no email. Two
 * reasons:
 *
 *  1. Those services recalculate from the order and would overwrite the figures
 *     the file exists to preserve.
 *  2. They refuse to run at all on a migrated order. `Order::canInvoice()` is
 *     false once `qty_invoiced` equals `qty_ordered` — which the order CSV
 *     already imported — so the service path cannot reproduce the invoices of an
 *     order it has just imported. The same applies to `canCreditmemo()` and
 *     `canShip()`.
 *
 * What the order aggregates (`total_invoiced`, `total_paid`, `qty_refunded`,
 * `qty_shipped`, …) do get is a full recompute from the documents now present —
 * see {@see OrderAggregateSynchronizerInterface}, controlled by the "Sync Order
 * Totals" setting.
 *
 * Nothing is written unless validation passes first.
 */
class Importer
{
    /** @var array<string, int|null> sku => product id (per-run cache) */
    private array $productIdCache = [];

    public function __construct(
        private readonly TypeInterface $type,
        private readonly Schema $schema,
        private readonly Validator $validator,
        private readonly Config $config,
        private readonly OrderAggregateSynchronizerInterface $orderSynchronizer,
        private readonly DocumentExtraData $extraData,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StateGuard $stateGuard,
        private readonly StockDeductionGuard $stockDeductionGuard,
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
     *     orders_synced: string[],
     *     failed: array<string, string>,
     *     skipped: array<string, string[]>
     * }
     */
    public function import(array $parsed, bool $skipInvalid = false): array
    {
        $this->productIdCache = [];
        $validation = $this->validator->validate($parsed);

        // Skip mode: drop every document that has a row-level error and import
        // the rest. A file-level error (missing header column, no data rows) is
        // reported on line 1 and still aborts — then no row can be trusted.
        $skipped = [];
        if ($skipInvalid && !$validation->isValid() && !$this->hasFileLevelError($validation)) {
            [$parsed, $skipped] = $this->withoutInvalidDocuments($parsed, $validation);
        } elseif (!$validation->isValid()) {
            $this->logger->warning(sprintf(
                'Import aborted — %s validation failed with %d error(s).',
                $this->type->getCode(),
                $validation->getSummary()['errors']
            ));
            return [
                'success' => false,
                'validation' => $validation,
                'created' => [],
                'updated' => [],
                'relinked' => [],
                'orders_synced' => [],
                'failed' => [],
                'skipped' => [],
            ];
        }

        // Group rows by document increment_id, preserving first-seen order.
        $grouped = [];
        foreach ($parsed['rows'] as $row) {
            $grouped[$row['data']['increment_id']][] = $row['data'];
        }

        $plan = $validation->getPlan();
        $created = [];
        $updated = [];
        $relinked = [];
        $failed = [];
        // Orders whose documents changed, so their aggregates need recomputing.
        $touchedOrders = [];

        foreach ($grouped as $incrementId => $itemRows) {
            $mode = $plan[(string)$incrementId]['mode'] ?? 'create';
            try {
                // No MSI stock deduction for an imported document — see StockDeductionGuard.
                if ($mode === 'update') {
                    $count = $this->stockDeductionGuard->run(
                        fn () => $this->updateDocument((string)$incrementId, $itemRows)
                    );
                    $updated[] = (string)$incrementId;
                    if ($count > 0) {
                        $relinked[(string)$incrementId] = $count;
                    }
                } else {
                    $this->stockDeductionGuard->run(fn () => $this->createDocument((string)$incrementId, $itemRows));
                    $created[] = (string)$incrementId;
                }
                $touchedOrders[(string)($itemRows[0]['order_increment_id'] ?? '')] = true;
            } catch (\Throwable $e) {
                $failed[(string)$incrementId] = ErrorMessage::describe($e);
                $this->logger->error(sprintf(
                    'Import failed for %s %s (%s): %s',
                    $this->type->getCode(),
                    $incrementId,
                    $mode,
                    ErrorMessage::describe($e)
                ));
            }
        }

        $synced = $this->syncOrders(array_keys($touchedOrders));

        $this->logger->info(sprintf(
            'Import finished (%s): %d created, %d updated, %d failed, %d skipped, %d item(s) re-linked, '
            . '%d order(s) resynced.',
            $this->type->getCode(),
            count($created),
            count($updated),
            count($failed),
            count($skipped),
            array_sum($relinked),
            count($synced)
        ));

        return [
            'success' => true,
            'validation' => $validation,
            'created' => $created,
            'updated' => $updated,
            'relinked' => $relinked,
            'orders_synced' => $synced,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }

    /**
     * Whether validation failed on the file as a whole rather than on a row.
     * The validator reports those on line 1 (the header line), which no data row
     * can have.
     */
    private function hasFileLevelError(ValidationResult $validation): bool
    {
        foreach ($validation->getErrors() as $error) {
            if ((int)$error['line'] <= 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove every document that has at least one row with a validation error.
     *
     * A document is all-or-nothing: importing an invoice with one of its item
     * rows missing would write wrong totals, so one bad row skips every row of
     * its increment_id. A bad row with no increment_id is listed under its line.
     *
     * @param array{header: string[], rows: array<int, array{line: int, data: array<string, string>}>} $parsed
     * @return array{0: array, 1: array<string, string[]>} the filtered file, and
     *     skipped increment_id (or "line N") => its error messages
     */
    private function withoutInvalidDocuments(array $parsed, ValidationResult $validation): array
    {
        $errorsByLine = [];
        foreach ($validation->getErrors() as $error) {
            $errorsByLine[(int)$error['line']][] = sprintf(
                'line %d, %s: %s',
                (int)$error['line'],
                $error['column'],
                $error['message']
            );
        }

        $skipped = [];
        foreach ($parsed['rows'] as $row) {
            if (!isset($errorsByLine[$row['line']])) {
                continue;
            }
            $key = (string)($row['data']['increment_id'] ?? '');
            $key = $key !== '' ? $key : 'line ' . $row['line'];
            $skipped[$key] = array_merge($skipped[$key] ?? [], $errorsByLine[$row['line']]);
        }

        $parsed['rows'] = array_values(array_filter(
            $parsed['rows'],
            static fn ($row) => !isset($skipped[(string)($row['data']['increment_id'] ?? '')])
                && !isset($errorsByLine[$row['line']])
        ));

        $this->logger->warning(sprintf(
            '%s import: skipping %d document(s) with validation errors.',
            $this->type->getCode(),
            count($skipped)
        ));

        return [$parsed, $skipped];
    }

    /**
     * Create one document from its CSV rows (all rows share one increment_id).
     *
     * @param array<int, array<string, string>> $itemRows
     */
    private function createDocument(string $incrementId, array $itemRows): void
    {
        $head = $itemRows[0];
        $order = $this->loadOrder((string)($head['order_increment_id'] ?? ''));

        $document = $this->type->createDocument();
        // Order first: both models copy the order reference into the item and
        // comment children as they are attached, so nothing may be attached
        // before this.
        $this->type->attachToOrder($document, $order);
        $document->setData('store_id', $this->resolveStoreId($head, $order));

        // Straight copy of every mapped document column.
        $this->applyMappedFields($document, $head, 'document');
        $document->setData('increment_id', $incrementId);
        if ($this->type->hasCurrencyColumns()) {
            $this->applyCurrencyFallbacks($document, $head, $order);
        }
        $this->type->applyExtraColumns($document, $head, $order);

        // Items. Each is linked back to an order item by SKU.
        $orderItemsBySku = $this->extraData->getOrderItemSkus([(int)$order->getId()])[(int)$order->getId()] ?? [];
        $orderItemsById = $this->orderItemsById($order);
        foreach ($itemRows as $row) {
            if (($row['item_sku'] ?? '') === '') {
                // A document exported with no items produces one all-empty item
                // row; there is nothing to build from it.
                continue;
            }
            $this->type->addItem($document, $this->buildItem($row, $orderItemsBySku, $orderItemsById));
        }

        $this->type->save($document);

        // Comments — and a shipment's tracks — only after the first save: every
        // model stamps the child's parent_id from the document's id at add time,
        // and that column is NOT NULL, so adding them to an unsaved document
        // would try to insert a null parent.
        $addedComments = $this->applyComments($document, (string)($head['document_comments'] ?? ''));
        $addedExtra = $this->type->applyPostSaveColumns($document, $head, $order);
        if ($addedComments || $addedExtra) {
            $this->type->save($document);
        }
    }

    /**
     * Update an existing document: its state, and a link for items that have
     * none. Amounts are deliberately not modified — a document is an accounting
     * record and its figures must not be rewritten by a re-import. A type with
     * no state column ({@see TypeInterface::getStateField()}, i.e. a shipment)
     * has only the re-linking to do here.
     *
     * Comments are not replayed either, nor a shipment's tracks: there is no key
     * to match an exported child row against an existing one, so a re-import
     * would duplicate the thread.
     *
     * @param array<int, array<string, string>> $itemRows every CSV row for this document
     * @return int how many items gained an order-item link
     */
    private function updateDocument(string $incrementId, array $itemRows): int
    {
        $head = $itemRows[0];
        $document = $this->type->loadByIncrementId($incrementId);
        if ($document === null) {
            throw new NoSuchEntityException(
                __('%1 %2 disappeared before update.', $this->type->getLabel(), $incrementId)
            );
        }

        $stateField = $this->type->getStateField();
        if ($stateField !== null) {
            $state = $head[$stateField] ?? '';
            if ($state !== '' && (int)$state !== (int)$document->getData($stateField)) {
                $document->setData($stateField, (int)$state);
            }
        }

        $order = $this->loadOrder((string)($head['order_increment_id'] ?? ''));
        $relinked = $this->relinkItems($document, $itemRows, $order);

        $this->type->save($document);

        if ($relinked > 0) {
            $this->logger->info(sprintf(
                '%s %s: re-linked %d item(s) to an order item.',
                $this->type->getLabel(),
                $incrementId,
                $relinked
            ));
        }

        return $relinked;
    }

    /**
     * Give an order-item link to document items that have none.
     *
     * A document item imported while the order's item could not be matched is
     * stored with `order_item_id = NULL`: the amounts are intact but the item
     * counts towards nothing, so the order's `qty_invoiced` / `qty_refunded` stay
     * short. Re-importing the file once the order item exists fills the link in.
     *
     * Scope is deliberately narrow, mirroring the order importer: an item is
     * touched only when it currently has NO order item AND its SKU appears in
     * the CSV being imported AND that SKU now resolves against the order. An
     * item that already points somewhere is never re-pointed.
     *
     * @param array<int, array<string, string>> $itemRows
     */
    private function relinkItems(AbstractModel $document, array $itemRows, Order $order): int
    {
        $csvSkus = [];
        foreach ($itemRows as $row) {
            $sku = $row['item_sku'] ?? '';
            if ($sku !== '') {
                $csvSkus[$sku] = true;
            }
        }
        if ($csvSkus === []) {
            return 0;
        }

        $orderItemsBySku = $this->extraData->getOrderItemSkus([(int)$order->getId()])[(int)$order->getId()] ?? [];
        $orderItemsById = $this->orderItemsById($order);

        $relinked = 0;
        foreach ($this->type->getItems($document) as $item) {
            if ((int)$item->getData('order_item_id') > 0) {
                continue;
            }
            $sku = (string)$item->getData('sku');
            if ($sku === '' || !isset($csvSkus[$sku], $orderItemsBySku[$sku])) {
                continue;
            }

            $orderItemId = $orderItemsBySku[$sku];
            $item->setData('order_item_id', $orderItemId);
            if ((int)$item->getData('product_id') <= 0) {
                $item->setData('product_id', $this->resolveProductId($sku, $orderItemsById[$orderItemId] ?? null));
            }
            $relinked++;
        }

        return $relinked;
    }

    /**
     * Recompute the aggregates of every order whose documents changed.
     *
     * Which aggregates those are is the injected synchroniser's business: the
     * invoiced / paid / refunded family for the money documents, the per-item
     * `qty_shipped` for shipments. See the di.xml wiring.
     *
     * Done once per order after all of its documents are in, not once per
     * document: the synchroniser is a full recompute, so running it per document
     * would repeat the same queries for no different answer.
     *
     * A failure here is logged and swallowed. The documents are already saved
     * and correct; refusing to report the import as done because a derived total
     * could not be refreshed would be the wrong trade.
     *
     * @param string[] $orderIncrementIds
     * @return string[] the orders that were resynced
     */
    private function syncOrders(array $orderIncrementIds): array
    {
        if (!$this->config->isSyncOrderTotalsEnabled()) {
            return [];
        }

        $synced = [];
        foreach (array_filter($orderIncrementIds) as $orderIncrementId) {
            try {
                $order = $this->loadOrder($orderIncrementId);
                $this->orderSynchronizer->apply($order);
                // Totals only: the order keeps its state/status. Without the guard
                // Magento re-derives the state on save and can close the order
                // (e.g. shipments imported but no paid invoice) — see StateGuard.
                $this->stateGuard->run(fn () => $this->orderRepository->save($order));
                $synced[] = $orderIncrementId;
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Could not resync totals for order %s after importing %ss: %s',
                    $orderIncrementId,
                    $this->type->getCode(),
                    ErrorMessage::describe($e)
                ));
            }
        }
        return $synced;
    }

    /**
     * Build one document item from a CSV row.
     *
     * @param array<string, string> $row
     * @param array<string, int> $orderItemsBySku
     * @param array<int, \Magento\Sales\Model\Order\Item> $orderItemsById
     */
    private function buildItem(array $row, array $orderItemsBySku, array $orderItemsById): AbstractModel
    {
        $item = $this->type->createItem();
        $this->applyMappedFields($item, $row, 'item');

        $sku = (string)($row['item_sku'] ?? '');
        $orderItemId = $orderItemsBySku[$sku] ?? null;
        if ($orderItemId !== null) {
            $item->setData('order_item_id', $orderItemId);
        }

        // Prefer the order item's product: it is the product this line was
        // actually sold as. Only fall back to a catalog lookup when the order
        // item could not be matched.
        $item->setData('product_id', $this->resolveProductId($sku, $orderItemsById[$orderItemId] ?? null));

        return $item;
    }

    /**
     * Copy every non-computed column of one source onto a record.
     *
     * Empty cells are skipped, except for the columns the type marks as
     * zero-defaulting — that keeps a nullable DB column NULL instead of writing
     * 0.0000 into it.
     *
     * @param array<string, string> $data
     * @param string $source document|item
     */
    private function applyMappedFields(DataObject $target, array $data, string $source): void
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
     * A document must carry a currency and a rate even if the CSV omits them,
     * otherwise its base amounts get converted at the store's live rate. The
     * order it belongs to is the right source for the fallback — by definition
     * the document is in the same currency.
     *
     * @param array<string, string> $head
     */
    private function applyCurrencyFallbacks(AbstractModel $document, array $head, Order $order): void
    {
        $orderCurrency = ($head['order_currency_code'] ?? '') ?: (string)$order->getOrderCurrencyCode();
        $baseCurrency = ($head['base_currency_code'] ?? '') ?: (string)$order->getBaseCurrencyCode();

        $document->setData('order_currency_code', $orderCurrency ?: $baseCurrency);
        $document->setData('base_currency_code', $baseCurrency ?: $orderCurrency);
        $document->setData(
            'global_currency_code',
            ($head['global_currency_code'] ?? '') ?: (string)$order->getGlobalCurrencyCode()
        );
        $document->setData(
            'store_currency_code',
            ($head['store_currency_code'] ?? '') ?: (string)$order->getStoreCurrencyCode()
        );

        foreach (['base_to_order_rate', 'base_to_global_rate', 'store_to_base_rate', 'store_to_order_rate'] as $rate) {
            if ((float)($head[$rate] ?? 0) <= 0.0) {
                $document->setData($rate, (float)$order->getData($rate));
            }
        }
    }

    /**
     * Recreate the document's comments from the packed JSON column.
     *
     * @return bool whether anything was added (and so a second save is needed)
     */
    private function applyComments(AbstractModel $document, string $json): bool
    {
        if ($json === '') {
            return false;
        }
        $entries = json_decode($json, true);
        if (!is_array($entries)) {
            return false;
        }

        $added = false;
        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $this->type->addComment($document, $entry);
                $added = true;
            }
        }
        return $added;
    }

    private function loadOrder(string $orderIncrementId): Order
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('increment_id', $orderIncrementId)->setPageSize(1);
        /** @var Order $order */
        $order = $collection->getFirstItem();
        if (!$order->getId()) {
            throw new NoSuchEntityException(__('Order %1 does not exist.', $orderIncrementId));
        }
        return $order;
    }

    /**
     * The store the document belongs to: the CSV's, falling back to the order's.
     *
     * @param array<string, string> $head
     */
    private function resolveStoreId(array $head, Order $order): int
    {
        $storeCode = (string)($head['store_code'] ?? '');
        if ($storeCode === '') {
            return (int)$order->getStoreId();
        }
        try {
            return (int)$this->storeManager->getStore($storeCode)->getId();
        } catch (NoSuchEntityException $e) {
            return (int)$order->getStoreId();
        }
    }

    /**
     * @return array<int, \Magento\Sales\Model\Order\Item> order item id => item
     */
    private function orderItemsById(Order $order): array
    {
        $items = [];
        foreach ($order->getAllItems() as $item) {
            $items[(int)$item->getItemId()] = $item;
        }
        return $items;
    }

    /**
     * The product a document line points at.
     *
     * The matched order item is authoritative — it records the product this line
     * was sold as, even if the SKU has since been reassigned. The catalog lookup
     * is only for an item with no order item to inherit from, and yields null
     * when the SKU is gone, which is a legal `product_id` on both item tables.
     */
    private function resolveProductId(string $sku, ?\Magento\Sales\Model\Order\Item $orderItem): ?int
    {
        if ($orderItem !== null && (int)$orderItem->getProductId() > 0) {
            return (int)$orderItem->getProductId();
        }
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
}
