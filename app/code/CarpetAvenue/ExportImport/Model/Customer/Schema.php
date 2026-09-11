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

use CarpetAvenue\ExportImport\Model\Eav\ExtraAttributes;
use Magento\Customer\Api\CustomerMetadataInterface;

/**
 * Single source of truth for the customer CSV column layout — the
 * `customer_entity` counterpart of
 * {@see \CarpetAvenue\ExportImport\Model\Order\Schema}.
 *
 * Both the exporter (writes these columns, in this order) and the importer /
 * validator (read and check against them) go through this class, so the two
 * halves can never drift out of sync.
 *
 * Row granularity: ONE ROW PER CUSTOMER. Addresses are a separate file — see
 * {@see \CarpetAvenue\ExportImport\Model\CustomerAddress\Schema} for why.
 *
 * ## How the map works
 *
 * An **ordered** `csv column => "customer:field[:num]"|"computed"` list. Order
 * of declaration is the order of the CSV header, so a column is declared exactly
 * once — its position, its origin and its type all come from the same line.
 *
 *   source  `customer` a column/attribute of `customer_entity`
 *           `computed` neither — built by the exporter and consumed by the
 *                      importer through code
 *   `:num`  validated as numeric on import and written as a float
 *
 * ## Scope: which columns are here and which are not
 *
 * Every business column of `customer_entity`, plus every EAV attribute the
 * install happens to have, the latter packed into `extra_attributes` (see
 * {@see ExtraAttributes}). Absent:
 *
 *  1. **Internal ids** — `entity_id`, `website_id`, `group_id`, `store_id`,
 *     `attribute_set_id`. Replaced by `email` + `website_code` (Magento's own
 *     unique key), `group_code` and `store_code`.
 *  2. **`default_billing` / `default_shipping`** — instance-local address ids.
 *     The address CSV carries them as `is_default_billing` /
 *     `is_default_shipping` flags on the address row, because that is the row
 *     that knows which address it is.
 *  3. **Login-lockout and session state** — `failures_num`, `first_failure`,
 *     `lock_expires`, `session_cutoff`. Transient, and importing a lockout onto
 *     a fresh install would lock a customer out of an account they can use.
 *  4. **`rp_token` / `rp_token_created_at`** — a live reset-password token with
 *     a configured lifetime; meaningless on the target instance, and a security
 *     token has no business travelling in a file.
 *  5. **`is_active`** — a column no EAV code path can write. `customer_entity`
 *     has it, but there is no `eav_attribute` row for it and the customer
 *     resource does not list it in `_getDefaultAttributes()`, so
 *     `Magento\Eav\Model\Entity\AbstractEntity::_collectSaveData()` drops the
 *     value (`if (empty($attribute)) continue;`). Magento itself never writes it
 *     either — it is a legacy column that stays at its `NOT NULL DEFAULT 1`.
 *     Carrying it would mean a column that exports a value and silently fails to
 *     import it, which is worse than not having the column.
 *  6. **Newsletter subscriptions** — `newsletter_subscriber` is its own table,
 *     keyed by email and store, and is NOT covered by this module. Migrating
 *     subscriptions is a separate job.
 *
 * `password_hash` **is** carried, and deliberately: without it every migrated
 * customer must reset their password before they can log in, which for a store
 * move is the difference between a migration and a mass password reset. It is a
 * hash, not a password — but see the README: the file it lands in is as
 * sensitive as a database dump.
 */
class Schema
{
    /** The CSV layout. */
    public const COLUMN_MAP = [
        // --- identity -------------------------------------------------------
        // email + website_code is the key: `customer_entity` enforces
        // (email, website_id) as unique, and so does the importer.
        'email' => 'customer:email',
        'website_code' => 'computed',
        'group_code' => 'computed',
        // The store view the account belongs to, and the human-readable
        // "Created From" label Magento stores alongside it.
        'store_code' => 'computed',
        'created_in' => 'customer:created_in',
        'increment_id' => 'customer:increment_id',
        'created_at' => 'customer:created_at',
        'updated_at' => 'customer:updated_at',

        // --- name -----------------------------------------------------------
        'prefix' => 'customer:prefix',
        'firstname' => 'customer:firstname',
        'middlename' => 'customer:middlename',
        'lastname' => 'customer:lastname',
        'suffix' => 'customer:suffix',

        // --- profile --------------------------------------------------------
        'dob' => 'customer:dob',
        // 1 male / 2 female / 3 not specified on a stock install — the option id,
        // not the label, so it cannot drift with translations.
        'gender' => 'customer:gender:num',
        'taxvat' => 'customer:taxvat',

        // --- account state --------------------------------------------------
        'disable_auto_group_change' => 'customer:disable_auto_group_change:num',
        // Non-empty means the account is awaiting email confirmation, and the
        // value is the key in the confirmation link. Carried so an unconfirmed
        // account stays unconfirmed instead of silently becoming usable.
        'confirmation' => 'customer:confirmation',
        // The stored hash, in Magento's `hash:salt:version` format. Carried so
        // customers keep their passwords; see the class docblock.
        'password_hash' => 'customer:password_hash',

        // --- everything else this install has -------------------------------
        // Every other EAV attribute of the customer entity, as one JSON cell.
        'extra_attributes' => 'computed',
    ];

    /**
     * Columns that MUST be present as a header and non-empty on every row.
     *
     * Only the key and the two names: everything else on a customer is
     * genuinely optional in Magento, and a migration must be able to carry a
     * legacy account that has no DOB and no phone.
     */
    public const REQUIRED_COLUMNS = [
        'email',
        'website_code',
        'firstname',
        'lastname',
    ];

    /** Columns whose cell holds a JSON document rather than a scalar. */
    public const JSON_COLUMNS = [
        'extra_attributes',
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

    /**
     * The attribute codes that ride in `extra_attributes` on this install.
     *
     * @return string[]
     */
    public function getExtraAttributeCodes(): array
    {
        return $this->extraAttributes->codesFor(
            CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER,
            array_values($this->getFields())
        );
    }
}
