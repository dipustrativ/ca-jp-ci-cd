<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;

class CurrencyConvertConfig extends AbstractHelper
{
    const XML_PATH_JPY_ROUNDING_ENABLED = 'productify/catalog_price/jpy_currency_ceil/enabled';
    const XML_PATH_JPY_THRESHOLDS = 'productify/catalog_price/jpy_currency_ceil/thresholds';

    public function __construct(
        Context $context
    )
    {
        parent::__construct($context);
    }

    public function enabled(): bool
    {
       return $this->scopeConfig->isSetFlag(
            self::XML_PATH_JPY_ROUNDING_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getJPThresholds(): array
    {
        $thresholds = $this->scopeConfig->getValue(
            self::XML_PATH_JPY_THRESHOLDS,
            ScopeInterface::SCOPE_STORE
        );
        if (!$thresholds) {
            return [];
        }

        $thresholdsArray = json_decode($thresholds, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        return array_values($thresholdsArray);

    }


}