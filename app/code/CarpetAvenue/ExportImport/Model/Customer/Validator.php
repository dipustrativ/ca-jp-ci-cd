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
use CarpetAvenue\ExportImport\Model\Order\ValidationResultFactory;
use CarpetAvenue\ExportImport\Model\ResourceModel\CustomerExtraData;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Validates a parsed customer CSV BEFORE any data is written — the "manual
 * check" gate, and the customer counterpart of
 * {@see \CarpetAvenue\ExportImport\Model\Document\Validator}.
 *
 * Errors block the import; warnings (an unknown store code, a customer with no
 * password hash) do not.
 *
 * The gate matters more here than anywhere else in the module, because
 * `Magento\Customer\Model\ResourceModel\Customer` validates almost nothing on
 * save: its `_validate()` is dead code (nothing calls it), and the only hard
 * guards are "email must be present" and "email must not already exist on the
 * website". Everything else a bad customer row could do — an unknown group, a
 * malformed date of birth, a duplicate inside the file — is caught here or not
 * at all.
 */
class Validator
{
    public function __construct(
        private readonly Schema $schema,
        private readonly ValidationResultFactory $resultFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerExtraData $extraData,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array{header: string[], rows: array<int, array{line: int, data: array<string, string>}>} $parsed
     */
    public function validate(array $parsed): ValidationResult
    {
        $result = $this->resultFactory->create();

        $header = $parsed['header'] ?? [];
        $rows = $parsed['rows'] ?? [];

        // 1. Header must carry every required column — otherwise nothing can proceed.
        $missing = array_diff($this->schema->getRequiredColumns(), $header);
        if (!empty($missing)) {
            foreach ($missing as $column) {
                $result->addError(1, $column, (string)__('Required column is missing from the CSV header.'));
            }
            return $result;
        }

        if (empty($rows)) {
            $result->addError(1, 'file', (string)__('The CSV contains no data rows.'));
            return $result;
        }

        $websiteIds = $this->websiteIdsByCode();
        $storeCodes = $this->validStoreCodes();
        $groupIds = $this->extraData->getGroupIdsByCode();
        $existing = $this->existingCustomers($rows, $websiteIds);
        $numericColumns = array_intersect($this->schema->getNumericColumns(), $header);
        $extraCodes = array_flip($this->schema->getExtraAttributeCodes());

        /** @var array<string, int> row key => the line that first claimed it */
        $seen = [];

        foreach ($rows as $row) {
            $line = $row['line'];
            $data = $row['data'];

            $email = trim((string)($data['email'] ?? ''));
            if ($email === '') {
                $result->addError($line, 'email', (string)__('email is required.'));
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $result->addError($line, 'email', (string)__('"%1" is not a valid email address.', $email));
            }

            $websiteCode = (string)($data['website_code'] ?? '');
            if ($websiteCode === '') {
                $result->addError($line, 'website_code', (string)__('website_code is required.'));
            } elseif (!isset($websiteIds[$websiteCode])) {
                $result->addError($line, 'website_code', (string)__('Unknown website code "%1".', $websiteCode));
            }

            // A customer is unique per (email, website) — the same pair twice in
            // one file would make the second row collide on insert.
            $key = $this->rowKey($data);
            if (isset($seen[$key])) {
                $result->addError(
                    $line,
                    'email',
                    (string)__(
                        'Email "%1" appears twice for website "%2" (first on line %3). One row per customer.',
                        $email,
                        $websiteCode,
                        $seen[$key]
                    )
                );
                continue;
            }
            $seen[$key] = $line;

            foreach (['firstname', 'lastname'] as $nameColumn) {
                if (trim((string)($data[$nameColumn] ?? '')) === '') {
                    $result->addError($line, $nameColumn, (string)__('%1 is required.', $nameColumn));
                }
            }

            $groupCode = (string)($data['group_code'] ?? '');
            if ($groupCode === '') {
                $result->addWarning(
                    $line,
                    'group_code',
                    (string)__('group_code is empty — the customer will be imported into the default group.')
                );
            } elseif (!isset($groupIds[strtolower($groupCode)])) {
                $result->addError(
                    $line,
                    'group_code',
                    (string)__(
                        'Unknown customer group "%1". Create the group first — the import does not create groups.',
                        $groupCode
                    )
                );
            }

            $storeCode = (string)($data['store_code'] ?? '');
            if ($storeCode !== '' && !isset($storeCodes[$storeCode])) {
                $result->addWarning(
                    $line,
                    'store_code',
                    (string)__(
                        'Unknown store code "%1" — the account will be attached to the website\'s default store.',
                        $storeCode
                    )
                );
            }

            $dob = (string)($data['dob'] ?? '');
            if ($dob !== '' && strtotime($dob) === false) {
                $result->addError(
                    $line,
                    'dob',
                    (string)__('"%1" is not a date Magento can read. Use Y-m-d.', $dob)
                );
            }

            $mode = isset($existing[$key]) ? 'update' : 'create';

            if ($mode === 'create' && (string)($data['password_hash'] ?? '') === '') {
                $result->addWarning(
                    $line,
                    'password_hash',
                    (string)__(
                        'password_hash is empty — the account is created without a password, so the customer '
                        . 'must use "Forgot Your Password?" before they can log in.'
                    )
                );
            }

            foreach ($numericColumns as $column) {
                $value = $data[$column] ?? '';
                if ($value !== '' && !is_numeric($value)) {
                    $result->addError($line, $column, (string)__('"%1" must be numeric, got "%2".', $column, $value));
                }
            }

            $this->validateExtraAttributes($result, $line, $data, $extraCodes);

            $result->registerOrder($key, $mode, $line);
        }

        $summary = $result->getSummary();
        $this->logger->info(sprintf(
            'Validated customer CSV: %d customer(s) [%d create / %d update], %d error(s), %d warning(s).',
            $summary['orders'],
            $summary['creates'],
            $summary['updates'],
            $summary['errors'],
            $summary['warnings']
        ));

        return $result;
    }

    /**
     * The upsert key of one row: its email and website.
     *
     * Lower-cased, because Magento treats customer emails case-insensitively
     * (`customer_entity` has a case-insensitive collation) and a file mixing
     * "A@b.com" and "a@b.com" is naming one account twice.
     *
     * @param array<string, string> $data
     */
    public function rowKey(array $data): string
    {
        return strtolower(trim((string)($data['email'] ?? ''))) . '|' . ($data['website_code'] ?? '');
    }

    /**
     * `extra_attributes` must be a JSON object, and every code in it must exist
     * on this install — an attribute that does not is a warning, not an error:
     * the rest of the customer is importable, and a source install with one
     * extra module is exactly the situation the column exists for.
     *
     * @param array<string, string> $data
     * @param array<string, int> $extraCodes
     */
    private function validateExtraAttributes(
        ValidationResult $result,
        int $line,
        array $data,
        array $extraCodes
    ): void {
        $json = (string)($data['extra_attributes'] ?? '');
        if ($json === '') {
            return;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            $result->addWarning(
                $line,
                'extra_attributes',
                (string)__('"extra_attributes" is not valid JSON — it will be ignored on import.')
            );
            return;
        }

        $unknown = array_diff(array_keys($decoded), array_keys($extraCodes));
        if ($unknown !== []) {
            $result->addWarning(
                $line,
                'extra_attributes',
                (string)__(
                    'This install has no customer attribute(s) "%1" — those values will be skipped.',
                    implode(', ', $unknown)
                )
            );
        }
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

    /** @return array<string, bool> */
    private function validStoreCodes(): array
    {
        $codes = [];
        foreach ($this->storeManager->getStores(true) as $store) {
            $codes[(string)$store->getCode()] = true;
        }
        return $codes;
    }

    /**
     * Which of the CSV's (email, website) pairs already exist here.
     *
     * @param array<int, array{line: int, data: array<string, string>}> $rows
     * @param array<string, int> $websiteIds
     * @return array<string, bool> keyed the same way as {@see rowKey()}
     */
    private function existingCustomers(array $rows, array $websiteIds): array
    {
        $pairs = [];
        $byRowKey = [];
        foreach ($rows as $row) {
            $email = trim((string)($row['data']['email'] ?? ''));
            $websiteCode = (string)($row['data']['website_code'] ?? '');
            if ($email === '' || !isset($websiteIds[$websiteCode])) {
                continue;
            }
            $websiteId = $websiteIds[$websiteCode];
            $pairs[] = [$email, $websiteId];
            $byRowKey[$this->extraData->customerKey($email, $websiteId)] = $this->rowKey($row['data']);
        }
        if ($pairs === []) {
            return [];
        }

        $found = [];
        foreach ($this->extraData->resolveCustomerIds($pairs) as $customerKey => $customerId) {
            if (isset($byRowKey[$customerKey])) {
                $found[$byRowKey[$customerKey]] = true;
            }
        }
        return $found;
    }
}
