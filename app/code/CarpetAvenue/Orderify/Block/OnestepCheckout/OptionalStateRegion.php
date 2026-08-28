<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Block\OnestepCheckout;

use Magento\Framework\View\Element\Template;
use Magento\Store\Model\ScopeInterface;

class OptionalStateRegion extends Template
{
    public function __construct(
        Template\Context $context,
        array $data = []
    )
    {
        parent::__construct($context, $data);
    }

    public function getOptionalSateCountries(): array
    {
        $isOptionalEnabled = $this->_scopeConfig->getValue(
            'general/region/display_all',
            ScopeInterface::SCOPE_STORE
        );
        if (!$isOptionalEnabled) {
            return [];
        }

        $isEnabled = $this->_scopeConfig->getValue(
            'orderify/region_settings/enable',
            ScopeInterface::SCOPE_STORE
        );

        if (!$isEnabled) {
            return [];
        }

        $optionalCountries = $this->_scopeConfig->getValue(
            'orderify/region_settings/optional_countries',
            ScopeInterface::SCOPE_STORE
        );
        if (empty($optionalCountries)) {
            return [];
        }

        return explode(',', $optionalCountries);
    }

    public function getRequiredSateCountries(): array
    {
        $requiredCountries = $this->_scopeConfig->getValue(
            'general/region/state_required',
            ScopeInterface::SCOPE_STORE
        );

        if (empty($requiredCountries)) {
            return [];
        }
        return explode(',', $requiredCountries);
    }

}