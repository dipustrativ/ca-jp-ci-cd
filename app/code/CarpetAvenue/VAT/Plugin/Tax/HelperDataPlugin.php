<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Ported from Evalent\PriceMods\Plugin\Tax\Helper\Data.
 * Forces shipping price to be treated as excluding tax when the order must not
 * be shipping-tax-free. Gated inside ShippingVatManager.
 *
 * @package CarpetAvenue_VAT
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\VAT\Plugin\Tax;

use CarpetAvenue\VAT\Model\ShippingVatManager;
use Magento\Tax\Helper\Data as TaxData;

class HelperDataPlugin
{
    public function __construct(
        private readonly ShippingVatManager $shippingVatManager
    ) {
    }

    /**
     * @param mixed $price
     * @param bool|null $includingTax
     * @param mixed $shippingAddress
     * @param mixed $ctc
     * @param mixed $store
     * @return array
     */
    public function beforeGetShippingPrice(
        TaxData $subject,
        $price,
        $includingTax = null,
        $shippingAddress = null,
        $ctc = null,
        $store = null
    ) {
        if ($this->shippingVatManager->forceShippingNotTaxfree($store)) {
            $includingTax = false;
        }
        return [$price, $includingTax, $shippingAddress, $ctc, $store];
    }
}
