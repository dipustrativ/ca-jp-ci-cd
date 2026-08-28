<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Plugins\Checkout;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Mageplaza\Osc\Block\Checkout\LayoutProcessor as MageplazaProcessor;

class MageplazaLayoutProcessor
{
    private const XML_PATH_JP_REGION_SORT = 'orderify/region_settings/jp_region_id_sort_enabled';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    private const PATH_SHIPPING_FIELDS = ['components', 'checkout', 'children', 'steps', 'children',
            'shipping-step', 'children', 'shippingAddress', 'children',
            'shipping-address-fieldset', 'children'];

    private const PATH_BILLING_FIELDS = ['components', 'checkout', 'children', 'steps', 'children',
            'shipping-step', 'children', 'billingAddress', 'children',
            'billing-address-fieldset', 'children'];

    public function afterProcess(MageplazaProcessor $subject, array $jsLayout): array
    {
        $shippingFields = $this->getNestedValue($jsLayout, self::PATH_SHIPPING_FIELDS);
        if ($shippingFields !== null) {
            $this->ensureCountryBeforeState($shippingFields);
            $this->addStreetLineNotes($shippingFields);
            $this->setNestedValue($jsLayout, self::PATH_SHIPPING_FIELDS, $shippingFields);
        }

        $billingFields = $this->getNestedValue($jsLayout, self::PATH_BILLING_FIELDS);
        if ($billingFields !== null) {
            $this->ensureCountryBeforeState($billingFields);
            $this->addStreetLineNotes($billingFields);
            $this->setNestedValue($jsLayout, self::PATH_BILLING_FIELDS, $billingFields);
        }

        return $jsLayout;
    }

    /**
     * Add helper notes below street lines and a visible label on the second line.
     */
    private function addStreetLineNotes(array &$fields): void
    {
        if (isset($fields['country_id']['value']) && strtoupper($fields['country_id']['value']) == "JP") {
            $street = &$fields['street']['children'];
            if (isset($street[1])) {
                $street[1]['label']= "";
            }

            return;
        }

        if (!isset($fields['street']['children']) || !is_array($fields['street']['children'])) {
            return;
        }

        $street = &$fields['street']['children'];

        if (isset($street[0])) {
            $street[0]['notice'] = __('Street address (street and number)');
        }

        if (isset($street[1])) {
            $street[1]['label'] = __('Address Line 2');
            $street[1]['notice'] = __('Suite, floor, c/o, etc. (optional)');
        }
    }

    private function ensureCountryBeforeState(array &$fields): void
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_JP_REGION_SORT, ScopeInterface::SCOPE_STORE)) {
            return;
        }

        if (!isset($fields['country_id'])) {
            return;
        }

        if (isset($fields['country_id']['value']) && strtoupper($fields['country_id']['value']) != "JP") {
            return;
        }

        $countrySort = (int)($fields['country_id']['sortOrder'] ?? 115);

        // country_id (Japan) → region_id → region → city (strict sequential order)
        if (isset($fields['region_id'])) {
            $fields['region_id']['sortOrder'] = (string)($countrySort + 1);
        }

        if (isset($fields['region'])) {
            $fields['region']['sortOrder'] = (string)($countrySort + 2);
        }

        if (isset($fields['city'])) {
            $fields['city']['sortOrder'] = (string)($countrySort + 3);
        }
    }


    private function getNestedValue(array $array, array $keys): ?array
    {
        $current = $array;
        foreach ($keys as $key) {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                return null;
            }
            $current = $current[$key];
        }
        return $current;
    }

    private function setNestedValue(array &$array, array $keys, array $value): void
    {
        $current = &$array;
        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }
        $current = $value;
    }
}