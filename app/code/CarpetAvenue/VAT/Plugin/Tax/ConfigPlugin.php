<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Ported from Evalent\PriceMods\Plugin\Tax\Model\Config.
 * Reports shipping as excluding tax when the order must not be shipping-tax-free.
 * Gated inside ShippingVatManager.
 *
 * @package CarpetAvenue_VAT
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\VAT\Plugin\Tax;

use CarpetAvenue\VAT\Model\ShippingVatManager;
use Magento\Tax\Model\Config as TaxConfig;

class ConfigPlugin
{
    public function __construct(
        private readonly ShippingVatManager $shippingVatManager
    ) {
    }

    /**
     * @param bool $result
     * @param mixed $store
     * @return bool
     */
    public function afterShippingPriceIncludesTax(TaxConfig $subject, $result, $store = null)
    {
        if ($this->shippingVatManager->forceShippingNotTaxfree($store)) {
            return false;
        }
        return $result;
    }
}
