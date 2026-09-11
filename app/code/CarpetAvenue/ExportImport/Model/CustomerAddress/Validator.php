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

use CarpetAvenue\Core\Logger\Logger;
use CarpetAvenue\ExportImport\Model\Order\ValidationResult;
use CarpetAvenue\ExportImport\Model\Order\ValidationResultFactory;
use CarpetAvenue\ExportImport\Model\ResourceModel\CustomerExtraData;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Validates a parsed customer-address CSV BEFORE any data is written — the
 * "manual check" gate for addresses.
 *
 * Errors block the import; warnings (a region that had to fall back to the CSV's
 * id, an address already present on the customer) do not.
 *
 * **A missing customer is an error**, like a document's missing order: an
 * address's `parent_id` is what makes it an address rather than a stray row, and
 * there is nowhere to put one whose customer is not here. Import customers
 * first, then their addresses.
 */
class Validator
{
    public function __construct(
        private readonly Schema $schema,
        private readonly ValidationResultFactory $resultFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerExtraData $extraData,
        private readonly CountryCollectionFactory $countryCollectionFactory,
        private readonly RegionResolver $regionResolver,
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
        $customerIds = $this->resolveCustomers($rows, $websiteIds);
        $existingFingerprints = $this->extraData->getAddressFingerprints(
            array_values($customerIds),
            $this->schema->getFingerprintFields()
        );
        $countryIds = $this->validCountryIds();
        $numericColumns = array_intersect($this->schema->getNumericColumns(), $header);
        $extraCodes = array_flip($this->schema->getExtraAttributeCodes());

        /** @var array<string, int> "customer key#fingerprint" => first line seen */
        $seenInFile = [];
        /** @var array<string, array<string, int>> customer key => flag => first line seen */
        $defaultsSeen = [];

        foreach ($rows as $row) {
            $line = $row['line'];
            $data = $row['data'];

            $email = trim((string)($data['customer_email'] ?? ''));
            $websiteCode = (string)($data['customer_website_code'] ?? '');
            $customerKey = $this->customerKey($data);

            if ($email === '') {
                $result->addError($line, 'customer_email', (string)__('customer_email is required.'));
                continue;
            }
            if ($websiteCode === '') {
                $result->addError(
                    $line,
                    'customer_website_code',
                    (string)__('customer_website_code is required.')
                );
            } elseif (!isset($websiteIds[$websiteCode])) {
                $result->addError(
                    $line,
                    'customer_website_code',
                    (string)__('Unknown website code "%1".', $websiteCode)
                );
            }

            $customerId = $customerIds[$customerKey] ?? null;
            if ($customerId === null && $websiteCode !== '' && isset($websiteIds[$websiteCode])) {
                $result->addError(
                    $line,
                    'customer_email',
                    (string)__(
                        'No customer "%1" on website "%2". The address cannot be imported without its customer '
                        . '— import the customers first.',
                        $email,
                        $websiteCode
                    )
                );
            }

            // The NOT NULL columns of customer_address_entity.
            foreach (['firstname', 'lastname', 'street', 'city', 'telephone', 'country_id'] as $required) {
                if (trim((string)($data[$required] ?? '')) === '') {
                    $result->addError($line, $required, (string)__('%1 is required.', $required));
                }
            }

            $countryId = strtoupper(trim((string)($data['country_id'] ?? '')));
            if ($countryId !== '' && !isset($countryIds[$countryId])) {
                $result->addError(
                    $line,
                    'country_id',
                    (string)__('Unknown country code "%1". Use the two-letter ISO code.', $countryId)
                );
            } elseif ($countryId !== '') {
                $this->validateRegion($result, $line, $data, $countryId);
            }

            foreach ($numericColumns as $column) {
                $value = $data[$column] ?? '';
                if ($value !== '' && !is_numeric($value)) {
                    $result->addError($line, $column, (string)__('"%1" must be numeric, got "%2".', $column, $value));
                }
            }

            $this->validateExtraAttributes($result, $line, $data, $extraCodes);

            // One customer cannot have two default billing (or shipping)
            // addresses; a file that says so does not say what it means.
            foreach (['is_default_billing', 'is_default_shipping'] as $flag) {
                if ((string)($data[$flag] ?? '') === '1') {
                    if (isset($defaultsSeen[$customerKey][$flag])) {
                        $result->addError(
                            $line,
                            $flag,
                            (string)__(
                                'Customer "%1" already has %2 = 1 on line %3. Only one address can be the default.',
                                $email,
                                $flag,
                                $defaultsSeen[$customerKey][$flag]
                            )
                        );
                    } else {
                        $defaultsSeen[$customerKey][$flag] = $line;
                    }
                }
            }

            $fingerprint = CustomerExtraData::fingerprint($this->fingerprintValues($data));
            $fileKey = $customerKey . '#' . $fingerprint;
            if (isset($seenInFile[$fileKey])) {
                $result->addWarning(
                    $line,
                    'street',
                    (string)__(
                        'This address is identical to line %1 for the same customer — it will be imported once.',
                        $seenInFile[$fileKey]
                    )
                );
            }
            $seenInFile[$fileKey] ??= $line;

            $alreadyPresent = $customerId !== null && isset($existingFingerprints[$customerId][$fingerprint]);
            if ($alreadyPresent) {
                $result->addWarning(
                    $line,
                    'street',
                    (string)__(
                        'Customer "%1" already has this address — it will be kept as it is, not duplicated. '
                        . 'Only the default-address flags can still change.',
                        $email
                    )
                );
            }

            // "update" here means "already on the customer, so nothing to write
            // but the default flags"; see the Importer.
            $result->registerOrder(
                $this->rowKey($data, $line),
                $alreadyPresent ? 'update' : 'create',
                $line
            );
        }

        $summary = $result->getSummary();
        $this->logger->info(sprintf(
            'Validated customer-address CSV: %d address(es) [%d new / %d already present], %d error(s), '
            . '%d warning(s).',
            $summary['orders'],
            $summary['creates'],
            $summary['updates'],
            $summary['errors'],
            $summary['warnings']
        ));

        return $result;
    }

    /**
     * The plan key of one row.
     *
     * An address has no key of its own, so the line number stands in for one.
     * That is stable for a given file, which is all the plan needs: it is read
     * back by the importer from the same parse.
     *
     * @param array<string, string> $data
     */
    public function rowKey(array $data, int $line): string
    {
        return $this->customerKey($data) . '#' . $line;
    }

    /**
     * The customer a row belongs to, keyed the way the customer CSV keys itself.
     *
     * @param array<string, string> $data
     */
    public function customerKey(array $data): string
    {
        return strtolower(trim((string)($data['customer_email'] ?? '')))
            . '|' . ($data['customer_website_code'] ?? '');
    }

    /**
     * The fingerprint input for one CSV row, in the schema's field order.
     *
     * @param array<string, string> $data
     * @return array<string, string>
     */
    public function fingerprintValues(array $data): array
    {
        $values = [];
        foreach ($this->schema->getFingerprintFields() as $field) {
            // The CSV column and the db field share a name for every
            // fingerprint field, which is why this can index by field.
            $values[$field] = (string)($data[$field] ?? '');
        }
        return $values;
    }

    /**
     * Resolve every row's customer, batched.
     *
     * @param array<int, array{line: int, data: array<string, string>}> $rows
     * @param array<string, int> $websiteIds
     * @return array<string, int> customer key => customer id
     */
    public function resolveCustomers(array $rows, array $websiteIds): array
    {
        $pairs = [];
        $byCustomerKey = [];
        foreach ($rows as $row) {
            $email = trim((string)($row['data']['customer_email'] ?? ''));
            $websiteCode = (string)($row['data']['customer_website_code'] ?? '');
            if ($email === '' || !isset($websiteIds[$websiteCode])) {
                continue;
            }
            $websiteId = $websiteIds[$websiteCode];
            $pairs[] = [$email, $websiteId];
            $byCustomerKey[$this->extraData->customerKey($email, $websiteId)] = $this->customerKey($row['data']);
        }

        $resolved = [];
        foreach ($this->extraData->resolveCustomerIds($pairs) as $customerKey => $customerId) {
            if (isset($byCustomerKey[$customerKey])) {
                $resolved[$byCustomerKey[$customerKey]] = $customerId;
            }
        }
        return $resolved;
    }

    /**
     * A region that could not be matched by name is the one case where the
     * import trusts an id from another instance, so it is worth a warning — and
     * a country that requires a region but has none named is worth a louder one.
     *
     * @param array<string, string> $data
     */
    private function validateRegion(ValidationResult $result, int $line, array $data, string $countryId): void
    {
        if (!$this->regionResolver->hasRegions($countryId)) {
            return;
        }

        if ($this->regionResolver->matchedByName($data)) {
            return;
        }

        $region = trim((string)($data['region'] ?? ''));
        $regionId = (int)($data['region_id'] ?? 0);

        if ($region === '' && $regionId <= 0) {
            $result->addWarning(
                $line,
                'region',
                (string)__(
                    'Country "%1" has a region list but this address names no region. It is imported without '
                    . 'one, which the admin address form will ask for the next time the address is edited.',
                    $countryId
                )
            );
            return;
        }

        $result->addWarning(
            $line,
            'region',
            (string)__(
                'Region "%1" is not in this install\'s list for country "%2", so region_id %3 from the CSV is '
                . 'used as-is. Check it points at the right region — region ids are only guaranteed to match '
                . 'between installs for regions Magento itself created.',
                $region !== '' ? $region : '(empty)',
                $countryId,
                $regionId > 0 ? $regionId : '(empty)'
            )
        );
    }

    /**
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
                    'This install has no customer-address attribute(s) "%1" — those values will be skipped.',
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
    private function validCountryIds(): array
    {
        $ids = [];
        foreach ($this->countryCollectionFactory->create()->getColumnValues('country_id') as $countryId) {
            $ids[strtoupper((string)$countryId)] = true;
        }
        return $ids;
    }
}
