<?php
namespace CarpetAvenue\Orderify\Plugins\Checkout;

use Magento\Checkout\Block\Checkout\LayoutProcessor as CheckoutLayoutProcessor;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class LayoutProcessor
{
    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        protected ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @param CheckoutLayoutProcessor $subject
     * @param array $jsLayout
     * @return array
     */
    public function afterProcess(
        CheckoutLayoutProcessor $subject,
        array $jsLayout
    ): array
    {
        // Check if custom region settings are enabled
        $isEnabled = $this->scopeConfig->getValue(
            'orderify/region_settings/enable',
            ScopeInterface::SCOPE_STORE
        );

        if (!$isEnabled) {
            return $jsLayout;
        }

        // Get countries where region should be optional
        $optionalCountries = $this->scopeConfig->getValue(
            'orderify/region_settings/optional_countries',
            ScopeInterface::SCOPE_STORE
        );

        if (empty($optionalCountries)) {
            return $jsLayout;
        }

        $optionalCountriesArray = explode(',', $optionalCountries);

        // Process Shipping Address Region (dropdown)
        if (isset($jsLayout['components']['checkout']['children']['steps']['children']
            ['shipping-step']['children']['shippingAddress']['children']
            ['shipping-address-fieldset']['children']['region_id'])) {

            $regionConfig = &$jsLayout['components']['checkout']['children']['steps']['children']
            ['shipping-step']['children']['shippingAddress']['children']
            ['shipping-address-fieldset']['children']['region_id'];

            // Add custom configuration to make it optional for selected countries
            $regionConfig['optionalCountries'] = $optionalCountriesArray;
        }

        // Process Shipping Address Region (text field)
        if (isset($jsLayout['components']['checkout']['children']['steps']['children']
            ['shipping-step']['children']['shippingAddress']['children']
            ['shipping-address-fieldset']['children']['region'])) {

            $regionFieldConfig = &$jsLayout['components']['checkout']['children']['steps']['children']
            ['shipping-step']['children']['shippingAddress']['children']
            ['shipping-address-fieldset']['children']['region'];

            // Add custom configuration to make it optional for selected countries
            $regionFieldConfig['optionalCountries'] = $optionalCountriesArray;
        }

        // Process Billing Address Region for all payment methods
        if (isset($jsLayout['components']['checkout']['children']['steps']['children']
            ['billing-step']['children']['payment']['children']['payments-list']['children'])) {

            $payments = &$jsLayout['components']['checkout']['children']['steps']['children']
            ['billing-step']['children']['payment']['children']['payments-list']['children'];

            foreach ($payments as $paymentCode => &$payment) {
                if (isset($payment['children']['form-fields']['children']['region_id'])) {
                    $payment['children']['form-fields']['children']['region_id']['optionalCountries'] = $optionalCountriesArray;
                }

                if (isset($payment['children']['form-fields']['children']['region'])) {
                    $payment['children']['form-fields']['children']['region']['optionalCountries'] = $optionalCountriesArray;
                }
            }
        }

        return $jsLayout;
    }
}

