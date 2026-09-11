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

use CarpetAvenue\Core\Logger\Logger;
use CarpetAvenue\ExportImport\Model\Order\ValidationResult;
use CarpetAvenue\ExportImport\Model\ResourceModel\CustomerExtraData;
use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Applies a validated customer CSV: upserts accounts by email + website.
 *
 *  - new (email, website)      -> CREATE the account from the CSV values
 *  - existing (email, website) -> UPDATE the profile attributes. `created_at` is
 *                                 never rewritten, and `password_hash` /
 *                                 `confirmation` are only filled in when the
 *                                 local account has none — a re-import must not
 *                                 change the password of an account someone is
 *                                 already using here.
 *
 * ## It writes the model, not the repository
 *
 * `CustomerRepositoryInterface::save()` is deliberately not used, for four
 * reasons that all amount to "it decides things the file has already decided":
 *
 *  1. On update it **overwrites `created_at`** with the stored value's — fine —
 *     but it also resets `rp_token`, re-derives the group and re-validates the
 *     default addresses, reloading the customer and its address list to do it.
 *  2. It runs the whole `CustomerInterface` conversion, which drops any
 *     attribute that is not on the interface unless it is passed as a
 *     `custom_attribute` — exactly the install-specific attributes
 *     `extra_attributes` exists to carry.
 *  3. It dispatches the extensible-data-object save events, which on this
 *     install reach third-party observers (newsletter sync, the Mageplaza SMTP
 *     marketing push). A migration must not notify anyone.
 *  4. `password_hash` can only reach it through a separate argument, and only
 *     on the paths that accept one.
 *
 * The model's own resource save is the honest floor: it keeps every `setData()`
 * value and enforces the two guards that matter (email present, email not
 * already on the website).
 *
 * What it does NOT reliably do is refresh the admin grid.
 * `Customer::afterSave()` schedules a `customer_grid` row reindex only when that
 * index is in state VALID and not in scheduled mode, so on a migration target —
 * where the index is usually invalidated — an imported customer saves correctly
 * and then **does not appear in the Customers grid at all**, because the grid
 * reads `customer_grid_flat`. The run therefore reindexes the rows it wrote
 * itself; see {@see GridReindexer}.
 *
 * Emails are never sent: nothing here goes through `AccountManagement`, which is
 * what sends the welcome and confirmation mails.
 *
 * Nothing is written unless validation passes first.
 */
class Importer
{
    /** Where Magento keeps the group a new account falls into. */
    private const XML_PATH_DEFAULT_GROUP = 'customer/create_account/default_group';

    public function __construct(
        private readonly Schema $schema,
        private readonly Validator $validator,
        private readonly CustomerFactory $customerFactory,
        private readonly CustomerResource $customerResource,
        private readonly GridReindexer $gridReindexer,
        private readonly CustomerExtraData $extraData,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
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
     *     grid_reindexed: int,
     *     failed: array<string, string>
     * }
     */
    public function import(array $parsed): array
    {
        $validation = $this->validator->validate($parsed);

        if (!$validation->isValid()) {
            $this->logger->warning(sprintf(
                'Import aborted — customer validation failed with %d error(s).',
                $validation->getSummary()['errors']
            ));
            return [
                'success' => false,
                'validation' => $validation,
                'created' => [],
                'updated' => [],
                'grid_reindexed' => 0,
                'failed' => [],
            ];
        }

        $rows = $parsed['rows'];
        $websiteIds = $this->websiteIdsByCode();
        $groupIds = $this->extraData->getGroupIdsByCode();
        $existing = $this->resolveExisting($rows, $websiteIds);

        $plan = $validation->getPlan();
        $created = [];
        $updated = [];
        $failed = [];
        // Ids of every customer this run wrote, for the grid reindex below.
        $touched = [];

        foreach ($rows as $row) {
            $data = $row['data'];
            $key = $this->validator->rowKey($data);
            $mode = $plan[$key]['mode'] ?? 'create';

            try {
                $websiteId = $websiteIds[(string)($data['website_code'] ?? '')] ?? null;
                if ($websiteId === null) {
                    // Validation rejects an unknown website, so reaching here
                    // means it was deleted between the check and the write.
                    throw new NoSuchEntityException(
                        __('Website "%1" no longer exists.', (string)($data['website_code'] ?? ''))
                    );
                }

                $customerId = $existing[$key] ?? null;
                if ($mode === 'update' && $customerId !== null) {
                    $this->updateCustomer($customerId, $data, $websiteId, $groupIds);
                    $updated[] = $key;
                    $touched[] = $customerId;
                } else {
                    $touched[] = $this->createCustomer($data, $websiteId, $groupIds);
                    $created[] = $key;
                }
            } catch (\Throwable $e) {
                $failed[$key] = $e->getMessage();
                $this->logger->error(sprintf(
                    'Import failed for customer %s (%s): %s',
                    $key,
                    $mode,
                    $e->getMessage()
                ));
            }
        }

        // The admin grid reads customer_grid_flat, not customer_entity: without
        // this the imported customers do not appear in it at all. See
        // GridReindexer for why the model's own hook cannot be relied on.
        $reindexed = $this->gridReindexer->reindex($touched);

        $this->logger->info(sprintf(
            'Import finished (customers): %d created, %d updated, %d failed, %d grid row(s) refreshed.',
            count($created),
            count($updated),
            count($failed),
            $reindexed
        ));

        return [
            'success' => true,
            'validation' => $validation,
            'created' => $created,
            'updated' => $updated,
            'grid_reindexed' => $reindexed,
            'failed' => $failed,
        ];
    }

    /**
     * Create one account from its CSV row.
     *
     * `attribute_set_id` is set explicitly, for the reason Magento's own
     * repository states where it does the same: an EAV row saved without it can
     * lose data on a later update.
     *
     * @param array<string, string> $data
     * @param array<string, int> $groupIds
     * @return int the new customer id
     */
    private function createCustomer(array $data, int $websiteId, array $groupIds): int
    {
        /** @var Customer $customer */
        $customer = $this->customerFactory->create();
        $this->applyMappedFields($customer, $data);
        $this->applyExtraAttributes($customer, $data);

        $customer->setWebsiteId($websiteId);
        $customer->setStoreId($this->resolveStoreId($data, $websiteId));
        $customer->setGroupId($this->resolveGroupId($data, $groupIds, $websiteId));
        $customer->setAttributeSetId(CustomerMetadataInterface::ATTRIBUTE_SET_ID_CUSTOMER);

        $this->customerResource->save($customer);
        return (int)$customer->getId();
    }

    /**
     * Update an existing account.
     *
     * Profile data is rewritten from the file — names, DOB, gender, taxvat,
     * group, store, the account flags and the install's extra attributes — since
     * a customer record is not an accounting record and the file is the
     * authority for who this person is.
     *
     * Three things are not: `created_at` (the local account's own history),
     * `password_hash` and `confirmation`, which are only filled in when the
     * local account has none. Rewriting a hash would change the password of an
     * account someone may already be using on this instance; rewriting
     * `confirmation` would push a confirmed account back into "unconfirmed".
     *
     * @param array<string, string> $data
     * @param array<string, int> $groupIds
     */
    private function updateCustomer(int $customerId, array $data, int $websiteId, array $groupIds): void
    {
        /** @var Customer $customer */
        $customer = $this->customerFactory->create();
        $this->customerResource->load($customer, $customerId);
        if (!$customer->getId()) {
            throw new NoSuchEntityException(
                __('Customer %1 disappeared before update.', (string)($data['email'] ?? ''))
            );
        }

        $skip = ['created_at' => true];
        foreach (['password_hash', 'confirmation'] as $secure) {
            if ((string)$customer->getData($secure) !== '') {
                $skip[$secure] = true;
            }
        }

        $this->applyMappedFields($customer, $data, $skip);
        $this->applyExtraAttributes($customer, $data);

        $customer->setStoreId($this->resolveStoreId($data, $websiteId));
        $customer->setGroupId($this->resolveGroupId($data, $groupIds, $websiteId));
        if (!$customer->getAttributeSetId()) {
            $customer->setAttributeSetId(CustomerMetadataInterface::ATTRIBUTE_SET_ID_CUSTOMER);
        }

        $this->customerResource->save($customer);
    }

    /**
     * Copy every mapped column onto the customer.
     *
     * Empty cells are skipped, so a nullable attribute stays NULL rather than
     * becoming an empty string, and an update leaves a value the file does not
     * carry alone.
     *
     * @param array<string, string> $data
     * @param array<string, bool> $skip db fields this call must not touch
     */
    private function applyMappedFields(Customer $customer, array $data, array $skip = []): void
    {
        foreach ($this->schema->getFields() as $column => $field) {
            if (isset($skip[$field])) {
                continue;
            }

            $value = $data[$column] ?? '';
            if ($value === '') {
                continue;
            }

            $customer->setData($field, $this->schema->isNumericColumn($column) ? (float)$value : $value);
        }
    }

    /**
     * Apply the install-specific attributes packed in `extra_attributes`.
     *
     * A code this install does not have is skipped — the validator has already
     * warned about it — so a file from an instance with one extra module still
     * imports everything else.
     *
     * @param array<string, string> $data
     */
    private function applyExtraAttributes(Customer $customer, array $data): void
    {
        $json = (string)($data['extra_attributes'] ?? '');
        if ($json === '') {
            return;
        }
        $values = json_decode($json, true);
        if (!is_array($values)) {
            return;
        }

        $known = array_flip($this->schema->getExtraAttributeCodes());
        foreach ($values as $code => $value) {
            if (isset($known[$code]) && $value !== null && $value !== '') {
                $customer->setData((string)$code, $value);
            }
        }
    }

    /**
     * The store view the account belongs to.
     *
     * The CSV's, falling back to the website's default store — never 0. A
     * customer with `store_id = 0` gets the admin scope's locale in every
     * transactional email, which is how a migrated Japanese customer ends up
     * receiving English mail.
     *
     * @param array<string, string> $data
     */
    private function resolveStoreId(array $data, int $websiteId): int
    {
        $storeCode = (string)($data['store_code'] ?? '');
        if ($storeCode !== '') {
            try {
                return (int)$this->storeManager->getStore($storeCode)->getId();
            } catch (NoSuchEntityException $e) {
                // Fall through to the website default.
            }
        }

        try {
            return (int)$this->storeManager->getWebsite($websiteId)->getDefaultStore()->getId();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * The group the account belongs to: the CSV's code, falling back to the
     * configured default group for the website.
     *
     * @param array<string, string> $data
     * @param array<string, int> $groupIds
     */
    private function resolveGroupId(array $data, array $groupIds, int $websiteId): int
    {
        $groupCode = strtolower((string)($data['group_code'] ?? ''));
        if ($groupCode !== '' && isset($groupIds[$groupCode])) {
            return $groupIds[$groupCode];
        }

        return (int)$this->scopeConfig->getValue(
            self::XML_PATH_DEFAULT_GROUP,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        );
    }

    /** @return array<string, int> website code => id */
    private function websiteIdsByCode(): array
    {
        $codes = [];
        foreach ($this->storeManager->getWebsites(true) as $website) {
            $codes[(string)$website->getCode()] = (int)$website->getId();
        }
        return $codes;
    }

    /**
     * The customer ids of the file's rows that already exist, keyed by row key.
     *
     * @param array<int, array{line: int, data: array<string, string>}> $rows
     * @param array<string, int> $websiteIds
     * @return array<string, int>
     */
    private function resolveExisting(array $rows, array $websiteIds): array
    {
        $pairs = [];
        $byCustomerKey = [];
        foreach ($rows as $row) {
            $email = trim((string)($row['data']['email'] ?? ''));
            $websiteCode = (string)($row['data']['website_code'] ?? '');
            if ($email === '' || !isset($websiteIds[$websiteCode])) {
                continue;
            }
            $websiteId = $websiteIds[$websiteCode];
            $pairs[] = [$email, $websiteId];
            $byCustomerKey[$this->extraData->customerKey($email, $websiteId)]
                = $this->validator->rowKey($row['data']);
        }

        $existing = [];
        foreach ($this->extraData->resolveCustomerIds($pairs) as $customerKey => $customerId) {
            if (isset($byCustomerKey[$customerKey])) {
                $existing[$byCustomerKey[$customerKey]] = $customerId;
            }
        }
        return $existing;
    }
}
