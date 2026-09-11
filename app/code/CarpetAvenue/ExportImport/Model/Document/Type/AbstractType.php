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

namespace CarpetAvenue\ExportImport\Model\Document\Type;

use CarpetAvenue\ExportImport\Model\Document\TypeInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Model\Order;

/**
 * What invoices and credit memos share.
 *
 * `sales_invoice_item` and `sales_creditmemo_item` are column-for-column
 * identical, so the item map lives here and neither subclass repeats it.
 * {@see Shipment} is the exception among the subclasses: `sales_shipment_item`
 * carries a small subset of those columns and nothing else, so it declares its
 * own `ITEM_COLUMN_MAP`. The document-level map is NOT shared: the tables
 * overlap heavily but not
 * exactly (a credit memo has the `adjustment_*` family and `invoice_id`, an
 * invoice has `total_qty`, `can_void_flag`, `is_used_for_refund` and
 * `base_total_refunded`), and column ORDER is the CSV header order — so each
 * subclass declares its own, one line per column, the same way
 * {@see \CarpetAvenue\ExportImport\Model\Order\Schema} does.
 *
 * ## Scope: which DB columns are here and which are not
 *
 * Every business column of the document and item tables is present. Absent:
 *
 *  1. **Internal ids and relations** — `entity_id`, `parent_id`, `store_id`,
 *     `order_id`, `product_id`, `order_item_id`, `billing_address_id`,
 *     `shipping_address_id`, and a credit memo's `invoice_id`. The CSV addresses
 *     the order by its increment id, the store by code, products and order items
 *     by SKU, and the parent invoice by ITS increment id, so a file moves
 *     between instances. Carrying source ids would defeat that.
 *  2. Nothing else. Unlike the order CSV there are no lifecycle aggregates to
 *     leave out (a document is itself the record of one), and no cardholder
 *     data on these tables.
 */
abstract class AbstractType implements TypeInterface
{
    /**
     * Item-level columns — one distinct set per CSV row. Identical for both types.
     *
     * `item_sku` is how an imported item finds its order item: the SKU is looked
     * up against the parent order's items. See Importer for the limitation that
     * follows from that.
     */
    public const ITEM_COLUMN_MAP = [
        'item_sku' => 'item:sku',
        'item_name' => 'item:name',
        'item_description' => 'item:description',
        'item_additional_data' => 'item:additional_data',
        'item_qty' => 'item:qty:num',

        // --- pricing ---------------------------------------------------------
        'item_price' => 'item:price:num',
        'item_base_price' => 'item:base_price:num',
        'item_price_incl_tax' => 'item:price_incl_tax:num',
        'item_base_price_incl_tax' => 'item:base_price_incl_tax:num',
        'item_row_total' => 'item:row_total:num',
        'item_base_row_total' => 'item:base_row_total:num',
        'item_row_total_incl_tax' => 'item:row_total_incl_tax:num',
        'item_base_row_total_incl_tax' => 'item:base_row_total_incl_tax:num',
        'item_base_cost' => 'item:base_cost:num',

        // --- tax --------------------------------------------------------------
        'item_tax_amount' => 'item:tax_amount:num',
        'item_base_tax_amount' => 'item:base_tax_amount:num',
        // The share of the order item's tax this document carries. Text, not a
        // number: Magento stores it as a serialised map, e.g. {"tax":0.5}.
        'item_tax_ratio' => 'item:tax_ratio',

        // --- discount ---------------------------------------------------------
        'item_discount_amount' => 'item:discount_amount:num',
        'item_base_discount_amount' => 'item:base_discount_amount:num',
        'item_discount_tax_compensation_amount' => 'item:discount_tax_compensation_amount:num',
        'item_base_discount_tax_compensation_amount' => 'item:base_discount_tax_compensation_amount:num',

        // --- FPT --------------------------------------------------------------
        'item_weee_tax_applied' => 'item:weee_tax_applied',
        'item_weee_tax_applied_amount' => 'item:weee_tax_applied_amount:num',
        'item_weee_tax_applied_row_amount' => 'item:weee_tax_applied_row_amount:num',
        'item_base_weee_tax_applied_amount' => 'item:base_weee_tax_applied_amount:num',
        'item_base_weee_tax_applied_row_amnt' => 'item:base_weee_tax_applied_row_amnt:num',
        'item_weee_tax_disposition' => 'item:weee_tax_disposition:num',
        'item_weee_tax_row_disposition' => 'item:weee_tax_row_disposition:num',
        'item_base_weee_tax_disposition' => 'item:base_weee_tax_disposition:num',
        'item_base_weee_tax_row_disposition' => 'item:base_weee_tax_row_disposition:num',
    ];

    /**
     * Columns that MUST be present as a header and non-empty on every row.
     *
     * `order_increment_id` is the one addition over the order CSV's list: a
     * document cannot exist without its order, so a row that does not name one
     * is unimportable.
     */
    public const REQUIRED_COLUMNS = [
        'increment_id',
        'order_increment_id',
        'store_code',
        'item_sku',
        'item_qty',
    ];

    /** Columns whose cell holds a JSON document rather than a scalar. */
    public const JSON_COLUMNS = [
        'document_comments',
    ];

    /**
     * Numeric columns that keep the "empty cell means 0" behaviour.
     *
     * Everything else numeric is left alone when its cell is empty, so a NULL in
     * the source database stays NULL rather than becoming 0.0000 — the
     * `*_incl_tax` family in particular is NULL on plenty of real documents.
     */
    public const ZERO_DEFAULT_COLUMNS = [
        'grand_total',
        'base_grand_total',
        'subtotal',
        'base_subtotal',
        'tax_amount',
        'base_tax_amount',
        'shipping_amount',
        'base_shipping_amount',
        'discount_amount',
        'base_discount_amount',
        'item_qty',
        'item_price',
        'item_base_price',
        'item_row_total',
        'item_base_row_total',
        'item_tax_amount',
        'item_base_tax_amount',
        'item_discount_amount',
        'item_base_discount_amount',
    ];

    /** The comment fields carried in the `document_comments` JSON cell. */
    protected const COMMENT_FIELDS = [
        'comment',
        'is_customer_notified',
        'is_visible_on_front',
        'created_at',
        // Not vanilla Magento — a third-party module adds these to both comment
        // tables. Exported when present so the file round-trips on this install.
        'user_id',
        'user_type',
    ];

    /** @inheritDoc */
    public function getItemColumnMap(): array
    {
        return static::ITEM_COLUMN_MAP;
    }

    /** @inheritDoc */
    public function getJsonColumns(): array
    {
        return static::JSON_COLUMNS;
    }

    /** @inheritDoc */
    public function getRequiredColumns(): array
    {
        return static::REQUIRED_COLUMNS;
    }

    /** @inheritDoc */
    public function getZeroDefaultColumns(): array
    {
        return static::ZERO_DEFAULT_COLUMNS;
    }

    /**
     * Nothing is export-only by default. Both subclasses add to this.
     *
     * @inheritDoc
     */
    public function getExportOnlyColumns(): array
    {
        return [];
    }

    /**
     * The money documents both keep a numeric `state`; {@see Shipment} overrides
     * this with null.
     *
     * @inheritDoc
     */
    public function getStateField(): ?string
    {
        return 'state';
    }

    /**
     * No states by default — a type with a `state` column declares its own
     * labels, because the tables number the states differently past 1.
     *
     * @inheritDoc
     */
    public function getStateOptions(): array
    {
        return [];
    }

    /**
     * A money document carries its own currency and rates; {@see Shipment}
     * overrides this with false.
     *
     * @inheritDoc
     */
    public function hasCurrencyColumns(): bool
    {
        return true;
    }

    /** @inheritDoc */
    public function exportExtraColumns(AbstractModel $document): array
    {
        return [];
    }

    /** @inheritDoc */
    public function applyExtraColumns(AbstractModel $document, array $data, Order $order): void
    {
        // Only the credit memo has extra columns; see Type\Creditmemo.
    }

    /** @inheritDoc */
    public function applyPostSaveColumns(AbstractModel $document, array $data, Order $order): bool
    {
        // Only the shipment has post-save data (its tracks); see Type\Shipment.
        return false;
    }

    /**
     * Normalise whatever `getItems()` handed back into a plain array.
     *
     * `Invoice::getItems()` returns an **array** for a document loaded from the
     * database but the item **Collection** object for one built in memory
     * (`getItemsCollection()` stores the collection under the same data key, and
     * `addItem()` goes through it). Both are iterable, neither is reliably an
     * array, so every caller goes through here.
     *
     * @param mixed $items
     * @return AbstractModel[]
     */
    protected function normalizeItems(mixed $items): array
    {
        if ($items instanceof \Magento\Framework\Data\Collection) {
            return array_values($items->getItems());
        }
        return is_array($items) ? array_values($items) : [];
    }

    /**
     * Read one document's comments as plain arrays, oldest first.
     *
     * Both models expose `getCommentsCollection()`; the ordering is applied here
     * rather than trusted, because the importer replays the entries in array
     * order and an unsorted export would recreate the thread backwards.
     *
     * @inheritDoc
     */
    public function getComments(AbstractModel $document): array
    {
        /**
         * @var \Magento\Sales\Model\Order\Invoice|\Magento\Sales\Model\Order\Creditmemo
         *     |\Magento\Sales\Model\Order\Shipment $document
         */
        $comments = $document->getCommentsCollection()->getItems();
        usort(
            $comments,
            static fn ($a, $b) => [$a->getCreatedAt(), (int)$a->getEntityId()]
                <=> [$b->getCreatedAt(), (int)$b->getEntityId()]
        );

        $entries = [];
        foreach ($comments as $comment) {
            $entry = [];
            foreach (static::COMMENT_FIELDS as $field) {
                $value = $comment->getData($field);
                if ($value !== null) {
                    $entry[$field] = $value;
                }
            }
            $entries[] = $entry;
        }
        return $entries;
    }
}
