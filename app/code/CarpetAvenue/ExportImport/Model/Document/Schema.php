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

/**
 * Single source of truth for one document type's CSV column layout — the
 * invoice / credit-memo counterpart of
 * {@see \CarpetAvenue\ExportImport\Model\Order\Schema}.
 *
 * Both the exporter (writes these columns, in this order) and the importer /
 * validator (reads and checks against them) go through this class, so the two
 * halves can never drift out of sync.
 *
 * Row granularity: ONE ROW PER DOCUMENT ITEM. Document-level columns are
 * repeated on every line belonging to the same document (keyed by
 * `increment_id`).
 *
 * ## How the maps work
 *
 * Each map is an **ordered** `csv column => "source:field[:num]"` list. Order of
 * declaration is the order of the CSV header, so a column is declared exactly
 * once — its position, its origin and its type all come from the same line.
 *
 *   source  `document` a column of `sales_invoice` / `sales_creditmemo`
 *           `item`     a column of `sales_invoice_item` / `sales_creditmemo_item`
 *           `computed` neither — built by the exporter (and consumed, or not,
 *                      by the importer). Declared as plain `computed`.
 *   `:num`  validated as numeric on import and written as a float.
 *
 * The maps themselves live on the {@see TypeInterface} implementation, because
 * they are the only thing that genuinely differs between the two document types.
 * One instance of this class exists per type (see the virtualTypes in di.xml).
 */
class Schema
{
    public function __construct(
        private readonly TypeInterface $type,
    ) {
    }

    public function getType(): TypeInterface
    {
        return $this->type;
    }

    /** Full ordered header: document columns followed by item columns. */
    public function getHeader(): array
    {
        return array_merge(
            array_keys($this->type->getDocumentColumnMap()),
            array_keys($this->type->getItemColumnMap())
        );
    }

    /** @return string[] */
    public function getRequiredColumns(): array
    {
        return $this->type->getRequiredColumns();
    }

    /**
     * Every column declared with `:num`.
     *
     * @return string[]
     */
    public function getNumericColumns(): array
    {
        $numeric = [];
        foreach ($this->allColumns() as $column => $spec) {
            if (str_ends_with($spec, ':num')) {
                $numeric[] = $column;
            }
        }
        return $numeric;
    }

    public function isNumericColumn(string $column): bool
    {
        return str_ends_with($this->allColumns()[$column] ?? '', ':num');
    }

    /** @return string[] */
    public function getJsonColumns(): array
    {
        return $this->type->getJsonColumns();
    }

    /**
     * Columns of one source, as `csv column => db field`.
     *
     * `computed` columns are excluded — they have no single backing field.
     *
     * @param string $source document|item
     * @return array<string, string>
     */
    public function getFieldsFor(string $source): array
    {
        $fields = [];
        foreach ($this->allColumns() as $column => $spec) {
            if ($spec === 'computed') {
                continue;
            }
            [$columnSource, $field] = explode(':', $spec);
            if ($columnSource === $source) {
                $fields[$column] = $field;
            }
        }
        return $fields;
    }

    public function isExportOnly(string $column): bool
    {
        return in_array($column, $this->type->getExportOnlyColumns(), true);
    }

    public function hasZeroDefault(string $column): bool
    {
        return in_array($column, $this->type->getZeroDefaultColumns(), true);
    }

    /** @return string[] item-column names, for the exporter's empty-row fallback */
    public function getItemColumns(): array
    {
        return array_keys($this->type->getItemColumnMap());
    }

    /**
     * Document map first so a column declared in both wins at document level —
     * the same precedence `+` gives in Order\Schema.
     *
     * @return array<string, string>
     */
    private function allColumns(): array
    {
        return $this->type->getDocumentColumnMap() + $this->type->getItemColumnMap();
    }
}
