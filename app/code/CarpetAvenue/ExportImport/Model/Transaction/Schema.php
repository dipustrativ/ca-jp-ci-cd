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

/**
 * Single source of truth for the payment-transaction CSV column layout — the
 * `sales_payment_transaction` counterpart of
 * {@see \CarpetAvenue\ExportImport\Model\Order\Schema} and
 * {@see \CarpetAvenue\ExportImport\Model\Document\Schema}.
 *
 * Both the exporter (writes these columns, in this order) and the importer /
 * validator (read and check against them) go through this class, so the two
 * halves can never drift out of sync.
 *
 * Row granularity: ONE ROW PER TRANSACTION. There are no child tables — a
 * transaction has no items and no comments — so unlike the invoice CSV there is
 * nothing repeated across rows and nothing packed into a JSON cell except the
 * gateway's own `additional_information` payload.
 *
 * ## Why transactions are not a `Model\Document\` type
 *
 * The document abstraction is built around a document row with an item table and
 * a comment table, addressed by `increment_id` and belonging to a store.
 * `sales_payment_transaction` has none of those: no items, no comments, no
 * increment id, no store column and no money on it at all. It is a flat log of
 * gateway calls, keyed by the gateway's own `txn_id` and parented to another
 * transaction rather than to items. Forcing it through the document Schema /
 * Exporter / Validator / Importer would have meant a flag per difference on
 * every one of them; this is the smaller, more honest half.
 *
 * ## How the map works
 *
 * An **ordered** `csv column => "transaction:field[:num]"|"computed"` list.
 * Order of declaration is the order of the CSV header, so a column is declared
 * exactly once — its position, its origin and its type all come from the same
 * line.
 *
 *   source  `transaction` a column of `sales_payment_transaction`
 *           `computed`    neither — built by the exporter and consumed by the
 *                         importer through code
 *   `:num`  validated as numeric on import and written as a float.
 *
 * ## Scope: which DB columns are here and which are not
 *
 * Every business column of the table is present. Absent:
 *
 *  1. **Internal ids and relations** — `transaction_id`, `parent_id`,
 *     `order_id`, `payment_id`. The CSV addresses the order by its increment id,
 *     the payment is re-resolved from that order (an order has exactly one
 *     `sales_order_payment` row), and the parent transaction is addressed by its
 *     `parent_txn_id`, which is the gateway's id and travels between instances.
 *  2. Nothing else. There is no cardholder data on this table — a gateway's
 *     response lands in `additional_information`, which is exported as it is
 *     stored; see the note on it in {@see Exporter}.
 */
class Schema
{
    /**
     * The CSV layout.
     *
     * `txn_id` is the key: it is the gateway's own transaction id, unique per
     * order and payment (`sales_payment_transaction` has a UNIQUE index on
     * order_id + payment_id + txn_id), which is why the importer upserts on
     * order_increment_id + txn_id rather than on txn_id alone.
     */
    public const COLUMN_MAP = [
        // --- identity -------------------------------------------------------
        'txn_id' => 'transaction:txn_id',
        // How the transaction finds its order — and through it its payment — on
        // the target instance.
        'order_increment_id' => 'computed',
        // The gateway id of the transaction this one hangs off (a capture's
        // authorization, a refund's capture). Empty for a root transaction.
        'parent_txn_id' => 'transaction:parent_txn_id',
        'txn_type' => 'transaction:txn_type',
        'is_closed' => 'transaction:is_closed:num',
        'created_at' => 'transaction:created_at',

        // --- gateway payload ------------------------------------------------
        // Magento stores this column as a serialised map; carried as JSON.
        'additional_information' => 'computed',
    ];

    /**
     * Columns that MUST be present as a header and non-empty on every row.
     *
     * `txn_type` is required alongside the two ids because Magento's own code
     * switches on it (`Transaction::close()`, the admin's Transactions grid,
     * every gateway's void/refund path); a transaction with no type is a row no
     * code can act on.
     */
    public const REQUIRED_COLUMNS = [
        'txn_id',
        'order_increment_id',
        'txn_type',
    ];

    /** Columns whose cell holds a JSON document rather than a scalar. */
    public const JSON_COLUMNS = [
        'additional_information',
    ];

    /** Full ordered header. */
    public function getHeader(): array
    {
        return array_keys(self::COLUMN_MAP);
    }

    /**
     * The upsert key of one CSV row: its order and its gateway transaction id.
     *
     * `txn_id` alone is not the key — the table's UNIQUE index is on
     * order_id + payment_id + txn_id, and a gateway that numbers per merchant
     * account rather than globally can legitimately reuse one across orders.
     * Both the validator (to plan create vs update, and to reject a row the file
     * repeats) and the importer (to look the plan back up) build it here so the
     * two agree by construction.
     *
     * @param array<string, string> $data one CSV row
     */
    public function rowKey(array $data): string
    {
        return ($data['order_increment_id'] ?? '') . '|' . ($data['txn_id'] ?? '');
    }

    /** @return string[] */
    public function getRequiredColumns(): array
    {
        return self::REQUIRED_COLUMNS;
    }

    /** @return string[] */
    public function getJsonColumns(): array
    {
        return self::JSON_COLUMNS;
    }

    /**
     * Every column declared with `:num`.
     *
     * @return string[]
     */
    public function getNumericColumns(): array
    {
        $numeric = [];
        foreach (self::COLUMN_MAP as $column => $spec) {
            if (str_ends_with($spec, ':num')) {
                $numeric[] = $column;
            }
        }
        return $numeric;
    }

    public function isNumericColumn(string $column): bool
    {
        return str_ends_with(self::COLUMN_MAP[$column] ?? '', ':num');
    }

    /**
     * The table-backed columns, as `csv column => db field`.
     *
     * `computed` columns are excluded — they have no single backing field.
     *
     * @return array<string, string>
     */
    public function getFields(): array
    {
        $fields = [];
        foreach (self::COLUMN_MAP as $column => $spec) {
            if ($spec === 'computed') {
                continue;
            }
            [, $field] = explode(':', $spec);
            $fields[$column] = $field;
        }
        return $fields;
    }
}
