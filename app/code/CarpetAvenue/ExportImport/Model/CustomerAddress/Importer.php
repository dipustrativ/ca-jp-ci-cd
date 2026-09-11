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
use CarpetAvenue\ExportImport\Model\Customer\GridReindexer;
use CarpetAvenue\ExportImport\Model\Order\ValidationResult;
use CarpetAvenue\ExportImport\Model\ResourceModel\CustomerExtraData;
use Magento\Customer\Api\AddressMetadataInterface;
use Magento\Customer\Model\Address;
use Magento\Customer\Model\AddressFactory;
use Magento\Customer\Model\ResourceModel\Address as AddressResource;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Applies a validated customer-address CSV against customers that already exist
 * here.
 *
 * ## The upsert rule: fingerprints, not ids
 *
 * An address has no key that survives a move between instances, so a row is
 * matched against the customer's existing addresses by a fingerprint of the
 * address fields ({@see Schema::FINGERPRINT_FIELDS}):
 *
 *  - **no match** -> CREATE the address from the CSV values
 *  - **match**    -> LEAVE IT ALONE. Only the default-billing /
 *                    default-shipping flags can still be applied, because those
 *                    live on the customer and may legitimately differ.
 *
 * What that buys: re-importing the same file is a no-op instead of giving every
 * customer a second copy of their address — the thing that makes an
 * address-import script dangerous to run twice.
 *
 * What it does not: an address that was **edited** on the source instance after
 * the first import has a different fingerprint, so it arrives as an additional
 * address rather than replacing the old one. That is the deliberate trade. The
 * alternative — matching loosely (say on postcode + street line 1) and
 * overwriting — would silently rewrite an address a customer may have corrected
 * here, and there is no way to tell the two situations apart from a CSV.
 *
 * ## It writes the model, with validation off
 *
 * `AddressRepositoryInterface` is not used, for the same reason the customer
 * importer avoids `CustomerRepositoryInterface`: it converts through
 * `AddressInterface` and drops install-specific attributes.
 *
 * `should_ignore_validation` is set, and that is a considered choice.
 * `Magento\Customer\Model\ResourceModel\Address::_beforeSave()` runs the
 * `customer_address` validator, whose rules depend on the TARGET install's
 * configuration — which countries are allowed, whether a region is required for
 * them, telephone requirements. A migration would then reject addresses the
 * source install accepted and its orders were shipped to, which loses history
 * for no gain. This module's own validator is the gate instead: it checks every
 * `NOT NULL` column, the country code and the region, and reports per line
 * before anything is written.
 *
 * Nothing is written unless validation passes first.
 */
class Importer
{
    public function __construct(
        private readonly Schema $schema,
        private readonly Validator $validator,
        private readonly AddressFactory $addressFactory,
        private readonly AddressResource $addressResource,
        private readonly GridReindexer $gridReindexer,
        private readonly CustomerExtraData $extraData,
        private readonly RegionResolver $regionResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array{header: string[], rows: array<int, array{line: int, data: array<string, string>}>} $parsed
     * @return array{
     *     success: bool,
     *     validation: ValidationResult,
     *     created: string[],
     *     skipped: string[],
     *     defaults_set: int,
     *     grid_reindexed: int,
     *     failed: array<string, string>
     * }
     */
    public function import(array $parsed): array
    {
        $validation = $this->validator->validate($parsed);

        if (!$validation->isValid()) {
            $this->logger->warning(sprintf(
                'Import aborted — customer-address validation failed with %d error(s).',
                $validation->getSummary()['errors']
            ));
            return [
                'success' => false,
                'validation' => $validation,
                'created' => [],
                'skipped' => [],
                'defaults_set' => 0,
                'grid_reindexed' => 0,
                'failed' => [],
            ];
        }

        $rows = $parsed['rows'];
        $websiteIds = $this->websiteIdsByCode();
        $customerIds = $this->validator->resolveCustomers($rows, $websiteIds);
        // Starts as what each customer already has and grows with everything
        // this run creates, so a file that repeats an address imports it once.
        $fingerprints = $this->extraData->getAddressFingerprints(
            array_values($customerIds),
            $this->schema->getFingerprintFields()
        );

        $created = [];
        $skipped = [];
        $failed = [];
        /** @var array<int, array<string, int>> customer id => flag => address id */
        $defaults = [];
        // Customers whose grid row may now be stale: the grid's billing_* columns
        // are derived from the default billing address.
        $touched = [];

        foreach ($rows as $row) {
            $data = $row['data'];
            $key = $this->validator->rowKey($data, $row['line']);
            $customerKey = $this->validator->customerKey($data);

            try {
                $customerId = $customerIds[$customerKey] ?? null;
                if ($customerId === null) {
                    // Validation rejects a missing customer, so reaching here
                    // means it was deleted between the check and the write.
                    throw new NoSuchEntityException(
                        __('Customer "%1" no longer exists.', (string)($data['customer_email'] ?? ''))
                    );
                }

                $fingerprint = CustomerExtraData::fingerprint($this->validator->fingerprintValues($data));
                $addressId = $fingerprints[$customerId][$fingerprint] ?? null;

                if ($addressId === null) {
                    $addressId = $this->createAddress($data, $customerId);
                    $fingerprints[$customerId][$fingerprint] = $addressId;
                    $created[] = $key;
                    $touched[] = $customerId;
                } else {
                    $skipped[] = $key;
                }

                foreach (['is_default_billing' => 'default_billing', 'is_default_shipping' => 'default_shipping']
                    as $flag => $column) {
                    if ((string)($data[$flag] ?? '') === '1') {
                        $defaults[$customerId][$column] = $addressId;
                        $touched[] = $customerId;
                    }
                }
            } catch (\Throwable $e) {
                $failed[$key] = $e->getMessage();
                $this->logger->error(sprintf(
                    'Import failed for customer address %s: %s',
                    $key,
                    $e->getMessage()
                ));
            }
        }

        $defaultsSet = $this->applyDefaults($defaults);
        // After the defaults, not before: the grid row is built from them, and
        // they are written with a statement that fires no model hook at all.
        $reindexed = $this->gridReindexer->reindex($touched);

        $this->logger->info(sprintf(
            'Import finished (customer addresses): %d created, %d already present, %d failed, '
            . '%d default address(es) set, %d grid row(s) refreshed.',
            count($created),
            count($skipped),
            count($failed),
            $defaultsSet,
            $reindexed
        ));

        return [
            'success' => true,
            'validation' => $validation,
            'created' => $created,
            'skipped' => $skipped,
            'defaults_set' => $defaultsSet,
            'grid_reindexed' => $reindexed,
            'failed' => $failed,
        ];
    }

    /**
     * Create one address from its CSV row.
     *
     * @param array<string, string> $data
     * @return int the local address id
     */
    private function createAddress(array $data, int $customerId): int
    {
        /** @var Address $address */
        $address = $this->addressFactory->create();
        $this->applyMappedFields($address, $data);
        $this->applyExtraAttributes($address, $data);

        $address->setCustomerId($customerId);
        $address->setAttributeSetId(AddressMetadataInterface::ATTRIBUTE_SET_ID_ADDRESS);
        $address->setData('region_id', $this->regionResolver->resolve($data));
        // See the class docblock: our validator is the gate, not the target
        // install's address configuration.
        $address->setData('should_ignore_validation', true);

        $this->addressResource->save($address);
        return (int)$address->getId();
    }

    /**
     * Copy every mapped column onto the address.
     *
     * `street` is passed through as the newline-joined string Magento stores,
     * not split into an array: `AbstractAddress::getStreet()` splits on read, so
     * the stored form is the canonical one and needs no conversion.
     *
     * Empty cells are skipped, so a nullable column stays NULL rather than
     * becoming an empty string.
     *
     * @param array<string, string> $data
     */
    private function applyMappedFields(Address $address, array $data): void
    {
        foreach ($this->schema->getFields() as $column => $field) {
            // region_id comes from the resolver, not straight from the cell.
            if ($field === 'region_id') {
                continue;
            }

            $value = $data[$column] ?? '';
            if ($value === '') {
                continue;
            }

            if ($field === 'country_id') {
                $value = strtoupper(trim($value));
            }

            $address->setData($field, $this->schema->isNumericColumn($column) ? (float)$value : $value);
        }
    }

    /**
     * Apply the install-specific attributes packed in `extra_attributes`.
     *
     * @param array<string, string> $data
     */
    private function applyExtraAttributes(Address $address, array $data): void
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
                $address->setData((string)$code, $value);
            }
        }
    }

    /**
     * Point each customer's default billing / shipping at the address the file
     * flagged.
     *
     * One statement per customer, after every address of the run is saved — see
     * {@see CustomerExtraData::setDefaultAddresses()} for why it is a statement
     * and not another customer save.
     *
     * @param array<int, array<string, int>> $defaults
     * @return int how many columns were written
     */
    private function applyDefaults(array $defaults): int
    {
        $count = 0;
        foreach ($defaults as $customerId => $values) {
            try {
                $this->extraData->setDefaultAddresses($customerId, $values);
                $count += count($values);
            } catch (\Throwable $e) {
                // The addresses themselves are saved and correct; a default
                // pointer that could not be written is worth a log line, not a
                // failed import.
                $this->logger->error(sprintf(
                    'Could not set the default address(es) of customer %d: %s',
                    $customerId,
                    $e->getMessage()
                ));
            }
        }
        return $count;
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
}
