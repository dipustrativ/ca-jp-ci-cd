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

namespace CarpetAvenue\ExportImport\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * The `customer_entity`, `customer_group` and `customer_address_entity` lookups
 * the customer and address export/import need.
 *
 * Three jobs, all of them translations between an instance-local id and
 * something a CSV can carry between instances:
 *
 *  - a customer's id ↔ its (email, website) pair, which is Magento's own unique
 *    key for `customer_entity`;
 *  - a customer group's id ↔ its code;
 *  - an address's id ↔ the customer it belongs to (`parent_id`).
 *
 * Plus the one write the address importer cannot do through a model: the
 * customer's `default_billing` / `default_shipping`; see
 * {@see setDefaultAddresses()}.
 *
 * Done in SQL so an export of 50 000 customers does not load 50 000 group or
 * customer models, and batched per file rather than per row.
 */
class CustomerExtraData
{
    /** @var array<int, array{email: string, website_id: int}> customer id => key parts */
    private array $customerKeyCache = [];

    /** @var array<int, string>|null group id => code */
    private ?array $groupCodes = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    /**
     * Resolve (email, website id) pairs to customer ids in one query.
     *
     * The pair is the key because that is what `customer_entity` enforces as
     * unique, and what `Magento\Customer\Model\ResourceModel\Customer::_beforeSave()`
     * checks for a duplicate. Email alone is not enough: the same person may
     * legitimately have an account on two websites.
     *
     * @param array<int, array{0: string, 1: int}> $pairs [[email, websiteId], ...]
     * @return array<string, int> "email|websiteId" => customer id, missing ones absent
     */
    public function resolveCustomerIds(array $pairs): array
    {
        $emails = [];
        foreach ($pairs as [$email, $websiteId]) {
            if ($email !== '') {
                $emails[strtolower($email)] = true;
            }
        }
        if ($emails === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        // Filtered on email only, then keyed by the pair in PHP: the email index
        // does the work, and the website is a cheap equality on the result.
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('customer_entity'),
                ['entity_id', 'email', 'website_id']
            )
            ->where('LOWER(email) IN (?)', array_keys($emails));

        $resolved = [];
        foreach ($connection->fetchAll($select) as $row) {
            $id = (int)$row['entity_id'];
            $email = strtolower((string)$row['email']);
            $websiteId = (int)$row['website_id'];
            $resolved[$this->customerKey($email, $websiteId)] = $id;
            $this->customerKeyCache[$id] = ['email' => (string)$row['email'], 'website_id' => $websiteId];
        }
        return $resolved;
    }

    /** The key {@see resolveCustomerIds()} returns, built the same way everywhere. */
    public function customerKey(string $email, int $websiteId): string
    {
        return strtolower($email) . '|' . $websiteId;
    }

    /**
     * The (email, website id) of a set of customers, for the address exporter.
     *
     * @param int[] $customerIds
     * @return array<int, array{email: string, website_id: int}>
     */
    public function getCustomerKeys(array $customerIds): array
    {
        $customerIds = array_values(array_unique(array_filter(array_map('intval', $customerIds))));
        $missing = array_values(array_diff($customerIds, array_keys($this->customerKeyCache)));

        if ($missing !== []) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from(
                    $this->resourceConnection->getTableName('customer_entity'),
                    ['entity_id', 'email', 'website_id']
                )
                ->where('entity_id IN (?)', $missing);
            foreach ($connection->fetchAll($select) as $row) {
                $this->customerKeyCache[(int)$row['entity_id']] = [
                    'email' => (string)$row['email'],
                    'website_id' => (int)$row['website_id'],
                ];
            }
        }

        $keys = [];
        foreach ($customerIds as $id) {
            if (isset($this->customerKeyCache[$id])) {
                $keys[$id] = $this->customerKeyCache[$id];
            }
        }
        return $keys;
    }

    /**
     * Customer group id => code, for the whole install.
     *
     * The code is what the CSV carries: group ids are assigned per install, so
     * the same "Wholesale" is id 2 here and id 5 there.
     *
     * @return array<int, string>
     */
    public function getGroupCodes(): array
    {
        if ($this->groupCodes === null) {
            $connection = $this->resourceConnection->getConnection();
            $rows = $connection->fetchPairs(
                $connection->select()->from(
                    $this->resourceConnection->getTableName('customer_group'),
                    ['customer_group_id', 'customer_group_code']
                )
            );
            $this->groupCodes = [];
            foreach ($rows as $id => $code) {
                $this->groupCodes[(int)$id] = (string)$code;
            }
        }
        return $this->groupCodes;
    }

    /**
     * Code => id, the direction the importer needs.
     *
     * Matched case-insensitively: "wholesale" and "Wholesale" are the same group
     * to a human writing a CSV, and Magento does not allow two codes differing
     * only in case.
     *
     * @return array<string, int>
     */
    public function getGroupIdsByCode(): array
    {
        $ids = [];
        foreach ($this->getGroupCodes() as $id => $code) {
            $ids[strtolower($code)] = $id;
        }
        return $ids;
    }

    /**
     * The addresses a set of customers already has, as field fingerprints.
     *
     * A customer address has **no business key** — no increment id, no code,
     * nothing a second instance could match on — so the address importer
     * recognises an already-imported address by the fingerprint of its own
     * fields. See {@see \CarpetAvenue\ExportImport\Model\CustomerAddress\Importer}
     * for what that means and does not mean.
     *
     * @param int[] $customerIds
     * @param string[] $fields the address columns the fingerprint is built from
     * @return array<int, array<string, int>> customer id => fingerprint => address id
     */
    public function getAddressFingerprints(array $customerIds, array $fields): array
    {
        $customerIds = array_values(array_unique(array_filter(array_map('intval', $customerIds))));
        if ($customerIds === [] || $fields === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('customer_address_entity'),
                array_merge(['entity_id', 'parent_id'], $fields)
            )
            ->where('parent_id IN (?)', $customerIds)
            ->order('entity_id ASC');

        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $values = [];
            foreach ($fields as $field) {
                $values[$field] = $row[$field] ?? null;
            }
            // First address wins for a duplicated fingerprint: two identical
            // addresses on one customer are indistinguishable, so the importer
            // must treat the file's row as already present rather than add a third.
            $fingerprint = self::fingerprint($values);
            $map[(int)$row['parent_id']][$fingerprint] ??= (int)$row['entity_id'];
        }
        return $map;
    }

    /**
     * A stable fingerprint of one address's field values.
     *
     * Values are trimmed and lower-cased so that whitespace or casing changes
     * between the two installs do not create a duplicate address; `street` is
     * normalised to single newlines for the same reason.
     *
     * @param array<string, mixed> $values field => value, in the caller's order
     */
    public static function fingerprint(array $values): string
    {
        $parts = [];
        foreach ($values as $field => $value) {
            $normalised = strtolower(trim((string)($value ?? '')));
            $normalised = preg_replace('/\s*\R\s*/', "\n", $normalised) ?? $normalised;
            $normalised = preg_replace('/[ \t]+/', ' ', $normalised) ?? $normalised;
            $parts[] = $field . '=' . $normalised;
        }
        return sha1(implode('|', $parts));
    }

    /**
     * Point a customer's `default_billing` / `default_shipping` at addresses.
     *
     * Written with a raw statement rather than through the customer model on
     * purpose. Both columns are plain address-id FKs on `customer_entity`, and
     * saving the customer model again to set them would re-run the whole EAV
     * save for two integers — and, through `CustomerRepository`, drag in
     * `validateDefaultAddress()`, which reloads the customer and its address
     * list. The addresses have just been saved and their ids are known; this is
     * the assignment, nothing more.
     *
     * Only the keys present are written, so a run that imports only shipping
     * defaults leaves the billing default alone.
     *
     * @param array<string, int> $defaults ['default_billing' => id, 'default_shipping' => id]
     */
    public function setDefaultAddresses(int $customerId, array $defaults): void
    {
        $values = array_intersect_key($defaults, array_flip(['default_billing', 'default_shipping']));
        if ($customerId <= 0 || $values === []) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName('customer_entity'),
            $values,
            ['entity_id = ?' => $customerId]
        );
    }
}
