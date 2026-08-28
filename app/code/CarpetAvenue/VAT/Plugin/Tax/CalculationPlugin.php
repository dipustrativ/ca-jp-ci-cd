<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Cross-border-trade net pricing per customer group. Reimplemented from
 * Evalent\PriceMods\Overwrite\Geissweb\Euvat\Plugin\Tax\Calculation against
 * Magento core only: when the customer's group is configured for net prices,
 * the customer tax class used for rate lookup is swapped to the configured
 * "Net Price Tax Class". No-ops unless both config fields are set.
 *
 * @package CarpetAvenue_VAT
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\VAT\Plugin\Tax;

use CarpetAvenue\VAT\Model\Config;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Tax\Model\Calculation;

class CalculationPlugin
{
    /** @var array<int,int|null> per-request memo of customerId => group id */
    private array $groupMemo = [];

    public function __construct(
        private readonly Config $config,
        private readonly CustomerSession $customerSession,
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
    }

    /**
     * @param mixed $shippingAddress
     * @param mixed $billingAddress
     * @param mixed $customerTaxClass
     * @param mixed $store
     * @param mixed $customerId
     * @return \Magento\Framework\DataObject
     */
    public function aroundGetRateRequest(
        Calculation $subject,
        callable $proceed,
        $shippingAddress = null,
        $billingAddress = null,
        $customerTaxClass = null,
        $store = null,
        $customerId = null
    ) {
        $groups = $this->config->disableCbtGroups($store);
        $excludingClass = $this->config->excludingTaxClass($store);

        // Feature off unless both the group list and the net-price class are set.
        if (empty($groups) || $excludingClass === null) {
            return $proceed($shippingAddress, $billingAddress, $customerTaxClass, $store, $customerId);
        }

        $groupId = $this->resolveGroupId((int)($customerId ?? 0));
        if ($groupId !== null && in_array($groupId, $groups, true)) {
            return $proceed($shippingAddress, $billingAddress, $excludingClass, $store, $customerId);
        }

        return $proceed($shippingAddress, $billingAddress, $customerTaxClass, $store, $customerId);
    }

    private function resolveGroupId(int $customerId): ?int
    {
        if ($this->customerSession->isLoggedIn()) {
            return (int)$this->customerSession->getCustomerGroupId();
        }
        if ($customerId <= 0) {
            return null;
        }
        // Cache the repository lookup: getRateRequest runs many times per page.
        if (array_key_exists($customerId, $this->groupMemo)) {
            return $this->groupMemo[$customerId];
        }
        try {
            return $this->groupMemo[$customerId] = (int)$this->customerRepository->getById($customerId)->getGroupId();
        } catch (\Exception $e) {
            return $this->groupMemo[$customerId] = null;
        }
    }
}
