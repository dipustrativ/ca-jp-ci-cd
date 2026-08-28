<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Store-country rate origin. Reimplemented from
 * Evalent\PriceMods\Overwrite\Geissweb\Euvat\Model\Rewrite\TaxCalculation
 * (Geissweb threshold-country logic dropped). getRateOriginRequest is protected,
 * so this must be a class override rather than a plugin. When the config switch
 * is off it defers to the stock behaviour.
 *
 * @package CarpetAvenue_VAT
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\VAT\Model\Tax;

use Magento\Directory\Helper\Data as DirectoryData;
use Magento\Framework\DataObject;
use Magento\Store\Model\ScopeInterface;

class Calculation extends \Magento\Tax\Model\Calculation
{
    /**
     * Use the store default country as the rate origin instead of the shipping
     * origin (fixes tax deduction on tax-free orders). Gated by config.
     *
     * @param null|string|bool|int|\Magento\Store\Model\Store $store
     * @return DataObject
     */
    protected function getRateOriginRequest($store = null)
    {
        $flag = $this->_scopeConfig->isSetFlag(
            \CarpetAvenue\VAT\Model\Config::XML_STORE_COUNTRY_ORIGIN,
            ScopeInterface::SCOPE_STORE,
            $store
        );

        if (!$flag) {
            return parent::getRateOriginRequest($store);
        }

        $request = new DataObject();
        $request->setData(
            'country_id',
            $this->_scopeConfig->getValue(
                DirectoryData::XML_PATH_DEFAULT_COUNTRY,
                ScopeInterface::SCOPE_STORE,
                $store
            )
        );
        $request->setData('customer_class_id', $this->getDefaultCustomerTaxClass($store));
        $request->setData('store', $store);

        return $request;
    }
}
