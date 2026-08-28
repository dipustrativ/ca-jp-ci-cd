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

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Locale\FormatInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Forces the JS price format to whole numbers. HideZeroDecimals only covers
 * server-rendered prices; the priceBox widget re-renders client-side using the
 * priceFormat config (precision/requiredPrecision), so decimals reappear on the
 * product detail page (configurable options, qty changes). Setting both to 0
 * makes priceUtils.js round and drop the decimals to match.
 */
class WholePriceFormat
{
    public function __construct(
        private readonly ScopeConfigInterface $config
    ) {
    }

    /**
     * @param FormatInterface $subject
     * @param array $result
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetPriceFormat(FormatInterface $subject, array $result): array
    {
        if (!$this->config->isSetFlag(HideZeroDecimals::XML_PATH_HIDE_ZEROS, ScopeInterface::SCOPE_STORE)) {
            return $result;
        }

        $result['precision'] = 0;
        $result['requiredPrecision'] = 0;

        return $result;
    }
}
