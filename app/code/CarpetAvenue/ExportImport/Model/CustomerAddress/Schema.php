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

use CarpetAvenue\ExportImport\Model\Eav\ExtraAttributes;
use Magento\Customer\Api\AddressMetadataInterface;

/**
 * Single source of truth for the customer-address CSV column layout — the
 * `customer_address_entity` counterpart of
 * {@see \CarpetAvenue\ExportImport\Model\Customer\Schema}.
 *
 * Row granularity: ONE ROW PER ADDRESS. The customer it belongs to is named by
 * `customer_email` + `customer_website_code`, the same key the customer CSV uses.
 *
 * ## Why addresses are a separate file
 *
 * A customer has 0..n addresses, so putting them in the customer CSV would mean
 * either one row per address with every customer column repeated (the shape the
 * order CSV uses, which would repeat a password hash on every line) or a
 * JSON-packed cell (which would make the addresses unreadable and un-editable in
 * a spreadsheet, the one place this data actually gets fixed up by hand). Two
 * files, imported customers-then-addresses, keeps both halves flat.
 *
 * ## An address has no business key
 *
 * There is nothing on `customer_address_entity` that identifies an address
 * across instances: no increment id, no code, and `entity_id` is local. So the
 * importer recognises an address it has already imported by a **fingerprint of
 * the address fields themselves** ({@see FINGERPRINT_FIELDS}) — see
 * {@see Importer} for exactly what that does and does not guarantee.
 *
 * ## Scope: which columns are here and which are not
 *
 * Every business column of `customer_address_entity`, plus every EAV attribute
 * the install happens to have via `extra_attributes`. Absent:
 *
 *  1. **Internal ids** — `entity_id`, `parent_id`, `region_id` as an
 *     authoritative value (see below), `attribute_set_id`.
 *  2. **`increment_id`** — a legacy column nothing in Magento reads or writes.
 *  3. **`is_active`** — a column no EAV code path can write: there is no
 *     `eav_attribute` row for it and it is not one of the entity's default
 *     static attributes, so
 *     `Magento\Eav\Model\Entity\AbstractEntity::_collectSaveData()` drops the
 *     value. It would export and then silently fail to import, which is worse
 *     than not having the column. (`created_at`, `updated_at`, `parent_id` and
 *     `increment_id` have no attribute row either, but they ARE in
 *     `_getDefaultAttributes()`, which is why `created_at` round-trips.)
 *  4. The customer's `default_billing` / `default_shipping`, which are on
 *     `customer_entity` and are carried here as the `is_default_billing` /
 *     `is_default_shipping` flags instead: the address row is the one that knows
 *     which address it is, and the flags survive the id change.
 *
 * `region_id` is carried, but as a **fallback, not the source of truth**: the
 * importer resolves the region from `country_id` + `region` (the name) first,
 * because a region id is only guaranteed to be the same on two installs when
 * both got the region from the same Magento data patch.
 */
class Schema
{
    /** The CSV layout. */
    public const COLUMN_MAP = [
        // --- which customer this address belongs to -------------------------
        'customer_email' => 'computed',
        'customer_website_code' => 'computed',

        // --- default-address flags (stored on the customer, see docblock) ----
        'is_default_billing' => 'computed',
        'is_default_shipping' => 'computed',

        // --- name -----------------------------------------------------------
        'prefix' => 'address:prefix',
        'firstname' => 'address:firstname',
        'middlename' => 'address:middlename',
        'lastname' => 'address:lastname',
        'suffix' => 'address:suffix',
        'company' => 'address:company',

        // --- the address itself ---------------------------------------------
        // Multi-line streets keep their newlines inside the one CSV cell; the
        // importer splits them back into Magento's street array.
        'street' => 'address:street',
        'city' => 'address:city',
        'region' => 'address:region',
        'region_id' => 'address:region_id:num',
        'postcode' => 'address:postcode',
        'country_id' => 'address:country_id',
        'telephone' => 'address:telephone',
        'fax' => 'address:fax',

        // --- VAT ------------------------------------------------------------
        'vat_id' => 'address:vat_id',
        'vat_is_valid' => 'address:vat_is_valid:num',
        'vat_request_date' => 'address:vat_request_date',
        'vat_request_id' => 'address:vat_request_id',
        'vat_request_success' => 'address:vat_request_success:num',

        // --- state ----------------------------------------------------------
        'created_at' => 'address:created_at',
        'updated_at' => 'address:updated_at',

        // --- everything else this install has -------------------------------
        'extra_attributes' => 'computed',
    ];

    /**
     * Columns that MUST be present as a header and non-empty on every row.
     *
     * The two key columns plus the six `NOT NULL` columns of
     * `customer_address_entity`. `postcode` is nullable in the schema (and
     * genuinely absent in a few countries), so it is not required here.
     */
    public const REQUIRED_COLUMNS = [
        'customer_email',
        'customer_website_code',
        'firstname',
        'lastname',
        'street',
        'city',
        'telephone',
        'country_id',
    ];

    /** Columns whose cell holds a JSON document rather than a scalar. */
    public const JSON_COLUMNS = [
        'extra_attributes',
    ];

    /**
     * The db fields whose values together identify an address.
     *
     * Chosen as "what a human would call the same address": who it is for, where
     * it is, and how to ring the doorbell. Deliberately NOT included:
     *
     *  - `created_at` / `updated_at` — timestamps differ between instances.
     *  - the `vat_*` family — validation state, refreshed by Magento itself.
     *  - `is_active` and the default flags — the same address can be the default
     *    on one instance and not on the other, and it is still the same address.
     */
    public const FINGERPRINT_FIELDS = [
        'firstname',
        'lastname',
        'company',
        'street',
        'city',
        'region',
        'postcode',
        'country_id',
        'telephone',
    ];

    public function __construct(
        private readonly ExtraAttributes $extraAttributes,
    ) {
    }

    /** Full ordered header. */
    public function getHeader(): array
    {
        return array_keys(self::COLUMN_MAP);
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

    /** @return string[] */
    public function getFingerprintFields(): array
    {
        return self::FINGERPRINT_FIELDS;
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
     * The attribute-backed columns, as `csv column => db field`.
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

    /**
     * The attribute codes that ride in `extra_attributes` on this install.
     *
     * @return string[]
     */
    public function getExtraAttributeCodes(): array
    {
        return $this->extraAttributes->codesFor(
            AddressMetadataInterface::ENTITY_TYPE_ADDRESS,
            array_values($this->getFields())
        );
    }
}
