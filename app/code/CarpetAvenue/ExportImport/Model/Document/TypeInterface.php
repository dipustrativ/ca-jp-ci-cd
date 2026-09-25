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

use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\Collection as CreditmemoCollection;
use Magento\Sales\Model\ResourceModel\Order\Invoice\Collection as InvoiceCollection;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Collection as ShipmentCollection;

/**
 * Everything that differs between an invoice, a credit memo and a shipment.
 *
 * `sales_invoice`, `sales_creditmemo` and `sales_shipment` are the same shape — a
 * document row, an item table and a comment table, all hanging off one order —
 * so Schema, Exporter, Validator and Importer under `Model\Document\` are
 * written once and parameterised by an implementation of this interface.
 * Anything that needs to know *which* document it is asks the type; nothing else
 * does.
 *
 * Implementations: {@see Type\Invoice}, {@see Type\Creditmemo}, {@see Type\Shipment}.
 */
interface TypeInterface
{
    /** Machine code used in URLs, CLI options and log lines: `invoice` / `creditmemo`. */
    public function getCode(): string;

    /** Human label for messages: `Invoice` / `Credit Memo`. */
    public function getLabel(): string;

    /** File-name stem for exports: `invoices` / `creditmemos`. */
    public function getFilePrefix(): string;

    /**
     * Document-level columns, as `csv column => "document:field[:num]"|"computed"`.
     *
     * @return array<string, string>
     */
    public function getDocumentColumnMap(): array;

    /**
     * Item-level columns, as `csv column => "item:field[:num]"|"computed"`.
     *
     * @return array<string, string>
     */
    public function getItemColumnMap(): array;

    /** @return string[] */
    public function getJsonColumns(): array;

    /** @return string[] */
    public function getExportOnlyColumns(): array;

    /** @return string[] */
    public function getRequiredColumns(): array;

    /** @return string[] */
    public function getZeroDefaultColumns(): array;

    /**
     * The document table's state column, or NULL when the type has none.
     *
     * Invoices and credit memos both keep a numeric `state`; `sales_shipment`
     * has no equivalent (its `shipment_status` is unused by Magento and is not a
     * lifecycle state), so the shipment type returns null and both the export
     * filter and the update path skip state entirely.
     */
    public function getStateField(): ?string;

    /**
     * Selectable states for the export filter, in admin display order.
     *
     * Empty when {@see getStateField()} is null. The labels live on the type
     * because the two tables number their states differently past 1.
     *
     * @return array<int, array{value: int, label: string}>
     */
    public function getStateOptions(): array;

    /**
     * Whether the document table carries its own currency codes and conversion
     * rates.
     *
     * True for invoices and credit memos — money documents, where a missing rate
     * silently reconverts every base amount at the store's live rate. False for
     * shipments: `sales_shipment` holds no amount and no currency at all, so
     * there is nothing to fall back on and nothing to warn about.
     */
    public function hasCurrencyColumns(): bool;

    /**
     * Whether a document of this type may have no items at all.
     *
     * True for credit memos only: a refund can be an adjustment alone
     * (`adjustment_positive` / `adjustment_negative`) with no product line.
     * Such a document exports as one row whose item columns are all empty, and
     * that row must validate. An invoice or shipment with no items records
     * nothing, so an empty item row stays an error there.
     */
    public function allowsItemlessDocument(): bool;

    /**
     * A collection of every document of this type, ready for the exporter to filter.
     *
     * @return InvoiceCollection|CreditmemoCollection|ShipmentCollection
     */
    public function createCollection();

    public function createDocument(): AbstractModel;

    public function createItem(): AbstractModel;

    /**
     * The document's items, in save order.
     *
     * @return AbstractModel[]
     */
    public function getItems(AbstractModel $document): array;

    /**
     * Attach an item, which is also what sets the item's back-reference to the
     * document (`Invoice::addItem()` / `Creditmemo::addItem()` both do this).
     */
    public function addItem(AbstractModel $document, AbstractModel $item): void;

    /**
     * The document's comments as plain arrays, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getComments(AbstractModel $document): array;

    /**
     * Recreate one comment from its exported array.
     *
     * @param array<string, mixed> $entry
     */
    public function addComment(AbstractModel $document, array $entry): void;

    /**
     * Point a new document at its order. Both models expose `setOrder()`, which
     * also fills `order_id` and the address ids on save.
     */
    public function attachToOrder(AbstractModel $document, Order $order): void;

    public function loadByIncrementId(string $incrementId): ?AbstractModel;

    /**
     * Persist through the type's repository, so the item/comment relations and the
     * `sales_*_grid` sync run exactly as they do for a natively created document.
     */
    public function save(AbstractModel $document): void;

    /**
     * Type-specific `computed` export columns (a credit memo's invoice link, say).
     *
     * @return array<string, string>
     */
    public function exportExtraColumns(AbstractModel $document): array;

    /**
     * Type-specific handling of `computed` columns on import — the mirror of
     * {@see exportExtraColumns()}.
     *
     * @param array<string, string> $data the document-level CSV row
     */
    public function applyExtraColumns(AbstractModel $document, array $data, Order $order): void;

    /**
     * Type-specific data that can only be written once the document has an id.
     *
     * Called after the first {@see save()}, alongside the comments, for the same
     * reason: a shipment's tracking rows take their `parent_id` from the
     * shipment at add time and that column is NOT NULL.
     *
     * @param array<string, string> $data the document-level CSV row
     * @return bool whether anything was added, i.e. whether the caller must save again
     */
    public function applyPostSaveColumns(AbstractModel $document, array $data, Order $order): bool;
}
