<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\LayeredNavigation\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const XML_PATH_ENABLED = 'productify/layerednav/general/enabled';
    private const XML_PATH_MULTISELECT = 'productify/layerednav/general/multiselect_enabled';
    private const XML_PATH_BATCH_APPLY = 'productify/layerednav/general/batch_apply';
    private const XML_PATH_AJAX = 'productify/layerednav/ajax/enabled';
    private const XML_PATH_SLIDER = 'productify/layerednav/slider/enabled';
    private const XML_PATH_SLIDER_STEP = 'productify/layerednav/slider/step';
    private const XML_PATH_SEO = 'productify/layerednav/seo/enabled';
    private const XML_PATH_SEO_INDEX_SINGLE = 'productify/layerednav/seo/allow_index_single_filter';
    private const XML_PATH_SEO_SEPARATOR = 'productify/layerednav/seo/multi_value_separator';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function isMultiselectEnabled(): bool
    {
        return $this->isEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_PATH_MULTISELECT, ScopeInterface::SCOPE_STORE);
    }

    public function isAjaxEnabled(): bool
    {
        return $this->isEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_PATH_AJAX, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Batch apply: filter clicks only stage a selection; nothing is applied
     * until the shopper clicks the "Show results" button. Requires AJAX.
     */
    public function isBatchApplyEnabled(): bool
    {
        return $this->isAjaxEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_PATH_BATCH_APPLY, ScopeInterface::SCOPE_STORE);
    }

    public function isSliderEnabled(): bool
    {
        return $this->isEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_PATH_SLIDER, ScopeInterface::SCOPE_STORE);
    }

    public function getSliderStep(): float
    {
        $step = (float)$this->scopeConfig->getValue(self::XML_PATH_SLIDER_STEP, ScopeInterface::SCOPE_STORE);

        return $step > 0 ? $step : 1.0;
    }

    public function isSeoEnabled(): bool
    {
        return $this->isEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_PATH_SEO, ScopeInterface::SCOPE_STORE);
    }

    public function isIndexSingleFilterAllowed(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_SEO_INDEX_SINGLE, ScopeInterface::SCOPE_STORE);
    }

    public function getMultiValueSeparator(): string
    {
        $separator = (string)$this->scopeConfig->getValue(self::XML_PATH_SEO_SEPARATOR, ScopeInterface::SCOPE_STORE);

        return $separator !== '' ? $separator : '_';
    }
}
