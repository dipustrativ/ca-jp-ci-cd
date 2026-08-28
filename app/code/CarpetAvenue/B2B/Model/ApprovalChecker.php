<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Central authority for "is this customer allowed to buy?". Shared by the
 * checkout observer, the place-order guard plugin and the pending banner block
 * so the rule lives in exactly one place.
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;

class ApprovalChecker
{
    public const ATTR_IS_APPROVED = 'is_approved';

    public function __construct(
        private readonly Config $config,
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
    }

    /**
     * Is the approval gate switched on for this scope?
     */
    public function isGateActive($store = null): bool
    {
        return $this->config->requireApproval($store);
    }

    /**
     * Read the approval flag from a customer data object.
     */
    public function isApprovedCustomer(CustomerInterface $customer): bool
    {
        $attr = $customer->getCustomAttribute(self::ATTR_IS_APPROVED);
        return $attr !== null && (int)$attr->getValue() === 1;
    }

    /**
     * Load a customer by id and read the approval flag.
     * Unknown / missing customers are treated as not approved.
     */
    public function isApprovedById(int $customerId): bool
    {
        if ($customerId <= 0) {
            return false;
        }
        try {
            return $this->isApprovedCustomer($this->customerRepository->getById($customerId));
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Should this logged-in customer be blocked from purchasing?
     */
    public function isBlocked(int $customerId, $store = null): bool
    {
        return $this->isGateActive($store) && !$this->isApprovedById($customerId);
    }
}
