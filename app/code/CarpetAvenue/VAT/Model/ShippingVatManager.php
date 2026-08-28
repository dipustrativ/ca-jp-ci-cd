<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Decides whether shipping must be taxed even on an otherwise tax-free order.
 * Ported from Evalent\PriceMods\Model\ShippingVatManager, with the Geissweb
 * getTargetGroupEu() dependency replaced by the configurable target group.
 * No-ops (returns false) unless "Force Shipping Not Tax-Free" is enabled.
 *
 * @package CarpetAvenue_VAT
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\VAT\Model;

use Magento\Backend\Model\Session\Quote as BackendQuoteSession;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Helper\Address as AddressHelper;
use Magento\Customer\Model\Address\AbstractAddress;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Store\Model\Store;

class ShippingVatManager
{
    /** @var array<string,bool> per-request memo, keyed by store id */
    private array $memo = [];

    public function __construct(
        private readonly Config $config,
        private readonly CheckoutSession $checkoutSession,
        private readonly BackendQuoteSession $backendQuoteSession,
        private readonly AddressHelper $addressHelper,
        private readonly State $state
    ) {
    }

    public function forceShippingNotTaxfree(?Store $store = null): bool
    {
        // Called repeatedly per price calculation from two plugins – memoize.
        $key = $store !== null ? (string)$store->getId() : 'default';
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = $this->resolve($store);
    }

    private function resolve(?Store $store): bool
    {
        if (!$this->config->shippingNotTaxfree($store)) {
            return false;
        }

        try {
            $quote = $this->isAdmin()
                ? $this->backendQuoteSession->getQuote()
                : $this->checkoutSession->getQuote();

            // Being in the configured target group wins over everything else.
            $targetGroup = $this->config->shippingTargetGroup($store);
            if ($targetGroup !== null && $targetGroup === (int)$quote->getCustomerGroupId()) {
                return true;
            }

            $type = $this->addressHelper->getTaxCalculationAddressType($store);
            $address = $type === AbstractAddress::TYPE_SHIPPING
                ? $quote->getShippingAddress()
                : $quote->getBillingAddress();

            return (bool)$address->getData('vat_is_valid');
        } catch (\Exception $e) {
            return false;
        }
    }

    private function isAdmin(): bool
    {
        try {
            return Area::AREA_ADMINHTML === $this->state->getAreaCode();
        } catch (\Exception $e) {
            return false;
        }
    }
}
