<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Customer;

use Exception;
use IXPF\Syncer\Helper\Logger\CustomerLogger;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Exception\State\InputMismatchException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Create a customer (and, optionally, its address book entries) from an IXPF payload.
 *
 * Uses the customer service contracts so password hashing, duplicate-email
 * checks, website scoping and address persistence all happen the Magento way.
 */
class CreateCustomer
{
    public function __construct(
        protected readonly CustomerRepositoryInterface $customerRepository,
        protected readonly CustomerInterfaceFactory    $customerFactory,
        protected readonly AddressInterfaceFactory      $addressFactory,
        protected readonly RegionInterfaceFactory       $regionFactory,
        protected readonly EncryptorInterface           $encryptor,
        protected readonly StoreManagerInterface        $storeManager,
        protected readonly CustomerLogger               $logger
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws Exception
     */
    public function create(array $payload): array
    {
        $this->validate($payload);

        $websiteId = isset($payload['website_id'])
            ? (int)$payload['website_id']
            : (int)$this->storeManager->getStore()->getWebsiteId();

        $email = trim((string)$payload['email']);

        $this->assertEmailAvailable($email, $websiteId);

        /** @var CustomerInterface $customer */
        $customer = $this->customerFactory->create();
        $customer->setWebsiteId($websiteId);
        $customer->setEmail($email);
        $customer->setFirstname(trim((string)$payload['firstname']));
        $customer->setLastname(trim((string)$payload['lastname']));

        if (isset($payload['store_id'])) {
            $customer->setStoreId((int)$payload['store_id']);
        }
        if (isset($payload['group_id'])) {
            $customer->setGroupId((int)$payload['group_id']);
        }
        if (isset($payload['prefix'])) {
            $customer->setPrefix((string)$payload['prefix']);
        }
        if (isset($payload['middlename'])) {
            $customer->setMiddlename((string)$payload['middlename']);
        }
        if (isset($payload['suffix'])) {
            $customer->setSuffix((string)$payload['suffix']);
        }
        if (isset($payload['dob'])) {
            $customer->setDob((string)$payload['dob']);
        }
        if (isset($payload['taxvat'])) {
            $customer->setTaxvat((string)$payload['taxvat']);
        }
        if (isset($payload['gender'])) {
            $customer->setGender((int)$payload['gender']);
        }

        $this->applyCustomAttributes($customer, $payload);

        $addresses = $this->buildAddresses($payload);
        if ($addresses) {
            $customer->setAddresses($addresses);
        }

        $passwordHash = null;
        if (!empty($payload['password'])) {
            $passwordHash = $this->encryptor->getHash((string)$payload['password'], true);
        }

        try {
            $saved = $this->customerRepository->save($customer, $passwordHash);
        } catch (NotFoundException $e) {
            // Anti-spam guard (CarpetAvenue\Spam) rejects the save with a bare
            // "Not found" — surface a meaningful reason to the caller instead.
            throw new Exception(
                'Customer rejected by spam filter: blocked email domain, blocked '
                . 'keyword in name/company, or a field exceeding the allowed length'
            );
        } catch (InputMismatchException $e) {
            throw new Exception("Customer with email '{$email}' already exists on this website");
        } catch (LocalizedException $e) {
            throw new Exception($e->getMessage());
        }

        $this->logger->writeDaily(
            "[customer_create] created customer ID {$saved->getId()} ({$saved->getEmail()}) "
            . 'with ' . count($saved->getAddresses() ?? []) . ' address(es)'
        );

        return $this->summarize($saved);
    }

    /**
     * @param array<string, mixed> $payload
     * @throws Exception
     */
    private function validate(array $payload): void
    {
        foreach (['email', 'firstname', 'lastname'] as $required) {
            if (empty($payload[$required])) {
                throw new Exception("Field '$required' is required");
            }
        }

        if (!filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email');
        }
    }

    /**
     * @throws Exception
     */
    private function assertEmailAvailable(string $email, int $websiteId): void
    {
        try {
            $this->customerRepository->get($email, $websiteId);
        } catch (NoSuchEntityException) {
            return; // free to use
        }

        throw new Exception("Customer with email '$email' already exists on this website");
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyCustomAttributes(CustomerInterface $customer, array $payload): void
    {
        if (isset($payload['phone_number'])) {
            $customer->setCustomAttribute('phone_number', $payload['phone_number']);
        }
        if (isset($payload['is_builder_account'])) {
            $customer->setCustomAttribute('is_builder_account', (int)(bool)$payload['is_builder_account']);
        }
    }

    /**
     * Accepts either a single "address" object or an "addresses" list.
     *
     * @param array<string, mixed> $payload
     * @return AddressInterface[]
     */
    private function buildAddresses(array $payload): array
    {
        $raw = [];
        if (!empty($payload['addresses']) && is_array($payload['addresses'])) {
            $raw = $payload['addresses'];
        } elseif (!empty($payload['address']) && is_array($payload['address'])) {
            $raw = [$payload['address']];
        }

        $addresses = [];
        foreach ($raw as $data) {
            if (!is_array($data)) {
                continue;
            }
            $addresses[] = $this->buildAddress($data);
        }

        return $addresses;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildAddress(array $data): AddressInterface
    {
        /** @var AddressInterface $address */
        $address = $this->addressFactory->create();

        $setters = [
            'firstname'  => 'setFirstname',
            'lastname'   => 'setLastname',
            'company'    => 'setCompany',
            'city'       => 'setCity',
            'postcode'   => 'setPostcode',
            'country_id' => 'setCountryId',
            'telephone'  => 'setTelephone',
            'fax'        => 'setFax',
            'vat_id'     => 'setVatId',
        ];
        foreach ($setters as $key => $setter) {
            if (isset($data[$key])) {
                $address->$setter($data[$key]);
            }
        }

        if (isset($data['street'])) {
            $street = is_string($data['street']) ? explode("\n", $data['street']) : (array)$data['street'];
            $address->setStreet($street);
        }

        if (!empty($data['region_id']) || !empty($data['region'])) {
            $region = $this->regionFactory->create();
            if (!empty($data['region_id'])) {
                $region->setRegionId((int)$data['region_id']);
            }
            if (!empty($data['region'])) {
                $region->setRegion((string)$data['region']);
            }
            if (!empty($data['region_code'])) {
                $region->setRegionCode((string)$data['region_code']);
            }
            $address->setRegion($region);
            if (!empty($data['region_id'])) {
                $address->setRegionId((int)$data['region_id']);
            }
        }

        $address->setIsDefaultBilling(!empty($data['is_default_billing']));
        $address->setIsDefaultShipping(!empty($data['is_default_shipping']));

        return $address;
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(CustomerInterface $customer): array
    {
        $addresses = [];
        foreach ($customer->getAddresses() ?? [] as $address) {
            $addresses[] = [
                'id'                 => (int)$address->getId(),
                'firstname'          => $address->getFirstname(),
                'lastname'           => $address->getLastname(),
                'street'             => $address->getStreet(),
                'city'               => $address->getCity(),
                'postcode'           => $address->getPostcode(),
                'country_id'         => $address->getCountryId(),
                'telephone'          => $address->getTelephone(),
                'is_default_billing' => (bool)$address->isDefaultBilling(),
                'is_default_shipping'=> (bool)$address->isDefaultShipping(),
            ];
        }

        return [
            'customer_id' => (int)$customer->getId(),
            'email'       => $customer->getEmail(),
            'firstname'   => $customer->getFirstname(),
            'lastname'    => $customer->getLastname(),
            'website_id'  => (int)$customer->getWebsiteId(),
            'group_id'    => (int)$customer->getGroupId(),
            'addresses'   => $addresses,
        ];
    }
}
