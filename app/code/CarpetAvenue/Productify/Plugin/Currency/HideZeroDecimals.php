<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Plugin\Currency;

use Magento\Directory\Model\Currency as DirectoryCurrency;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\CurrencyInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Forces whole-number price display — 75.00 kr renders as 75 kr, 75.50 kr
 * rounds to 76 kr (precision 0 lets the formatter round the value). Ported
 * from Evalent_PriceFormat (base project). The base
 * plugged \Zend_Currency::toCurrency; in Magento 2.4.9 formatTxt formats via
 * NumberFormatter and only falls back to toCurrency for exotic options, so
 * both entry points are covered here.
 */
class HideZeroDecimals
{
    public const XML_PATH_HIDE_ZEROS = 'productify/catalog_price/hide_zeros';

    public function __construct(
        private readonly ScopeConfigInterface $config
    ) {
    }

    /**
     * Set format precision to 0 when the value has no decimals.
     *
     * @param CurrencyInterface $subject
     * @param float|int|string|null $value
     * @param array $options
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeToCurrency(CurrencyInterface $subject, $value = null, array $options = []): array
    {
        if (!$this->config->isSetFlag(self::XML_PATH_HIDE_ZEROS, ScopeInterface::SCOPE_STORE)) {
            return [$value, $options];
        }

        if (is_numeric($value)) {
            // Force whole-number display: precision 0 makes the formatter round
            // the value (75.50 kr -> 76 kr, 75.00 kr -> 75 kr).
            $options['precision'] = 0;
        }

        return [$value, $options];
    }

    /**
     * Same for the NumberFormatter path used by all standard price rendering.
     *
     * @param DirectoryCurrency $subject
     * @param float|int|string $price
     * @param array $options
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeFormatTxt(DirectoryCurrency $subject, $price, $options = []): array
    {
        if (!$this->config->isSetFlag(self::XML_PATH_HIDE_ZEROS, ScopeInterface::SCOPE_STORE)) {
            return [$price, $options];
        }

        if (is_numeric($price)) {
            // Force whole-number display: precision 0 makes the formatter round
            // the value (75.50 kr -> 76 kr, 75.00 kr -> 75 kr).
            $options['precision'] = 0;
        }

        return [$price, $options];
    }
}
