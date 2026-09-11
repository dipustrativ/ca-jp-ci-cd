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

namespace CarpetAvenue\ExportImport\Model\Eav;

use Magento\Eav\Model\Config as EavConfig;

/**
 * The EAV attributes of an entity type that the CSV column maps do NOT name.
 *
 * Customers and customer addresses are EAV entities, so unlike a sales table
 * their column set is **install-specific**: this install alone has
 * `is_approved` on the customer (added by `CarpetAvenue_B2B`), and any module or
 * admin user can add another tomorrow. A hardcoded column map would silently
 * drop them, which for a migration means losing data with no error.
 *
 * So the declared maps in `Model\Customer\Schema` and
 * `Model\CustomerAddress\Schema` cover the standard attributes — a stable,
 * reviewable CSV header — and everything else the entity type happens to have
 * is packed into the single `extra_attributes` JSON cell, resolved here at
 * runtime in both directions. Add an attribute to the install and it starts
 * round-tripping with no code change.
 *
 * Excluded by name (see {@see EXCLUDED}) even though they are attributes: values
 * that must not travel between installs.
 */
class ExtraAttributes
{
    /**
     * Attribute codes never carried in `extra_attributes`, whatever the entity.
     *
     *  - `password_hash`, `rp_token`, `rp_token_created_at` — the customer map
     *    decides about the hash explicitly; the reset-password token is a live
     *    security token with a lifetime, meaningless on another instance.
     *  - `default_billing`, `default_shipping` — instance-local address ids. The
     *    address CSV carries them as flags instead; see
     *    {@see \CarpetAvenue\ExportImport\Model\CustomerAddress\Schema}.
     *  - `failures_num`, `first_failure`, `lock_expires`, `session_cutoff` —
     *    login-lockout and session state. Transient, and importing a lockout
     *    onto a fresh install would lock a customer out for no reason.
     *  - `mp_smtp_email_marketing_synced` — a Mageplaza SMTP bookkeeping flag
     *    saying whether the row has been pushed to their marketing service. It
     *    describes the source install's sync state, not the customer.
     *  - `attribute_set_id`, `entity_type_id`, `increment_id`, `updated_at`,
     *    `created_at`, `entity_id`, `parent_id` — structural columns the maps
     *    and the importers handle themselves.
     */
    public const EXCLUDED = [
        'password_hash',
        'rp_token',
        'rp_token_created_at',
        'default_billing',
        'default_shipping',
        'failures_num',
        'first_failure',
        'lock_expires',
        'session_cutoff',
        'mp_smtp_email_marketing_synced',
        'attribute_set_id',
        'entity_type_id',
        'increment_id',
        'entity_id',
        'parent_id',
        'created_at',
        'updated_at',
    ];

    /** @var array<string, string[]> entity type code => attribute codes (per-run cache) */
    private array $cache = [];

    public function __construct(
        private readonly EavConfig $eavConfig,
    ) {
    }

    /**
     * The attribute codes of one entity type that are neither mapped nor excluded.
     *
     * @param string $entityType `customer` / `customer_address`
     * @param string[] $mappedFields the db fields the CSV already has columns for
     * @return string[] sorted, so the JSON cell is diffable between exports
     */
    public function codesFor(string $entityType, array $mappedFields): array
    {
        $key = $entityType . '|' . implode(',', $mappedFields);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $skip = array_flip(array_merge($mappedFields, self::EXCLUDED));
        $codes = [];
        foreach ($this->eavConfig->getEntityAttributeCodes($entityType) as $code) {
            if (!isset($skip[$code])) {
                $codes[] = $code;
            }
        }
        sort($codes);

        return $this->cache[$key] = $codes;
    }
}
