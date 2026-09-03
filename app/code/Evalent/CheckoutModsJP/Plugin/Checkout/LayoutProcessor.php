<?php
namespace Evalent\CheckoutModsJP\Plugin\Checkout;

use Magento\Store\Model\StoreManagerInterface;
use Evalent\CheckoutModsJP\Api\Data\CheckoutConfigsJpInterface;

/**
 * class LayoutProcessor
 */
class LayoutProcessor implements CheckoutConfigsJpInterface
{
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * LayoutProcessor constructor
     *
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     */
    public function __construct(
        StoreManagerInterface $storeManager
    )
    {
        $this->_storeManager = $storeManager;
    }

    /**
     * @param \Mageplaza\Osc\Block\Checkout\LayoutProcessor $subject
     * @param array $jsLayout
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function afterProcess(
        \Mageplaza\Osc\Block\Checkout\LayoutProcessor $subject,
                                                array $jsLayout
    )
    {
        $storeCode = $this->_storeManager->getStore()->getCode();
        if ($storeCode !== $this::STORE_CODE_JP) {
            return $jsLayout;
        }

        $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
        ['shippingAddress']['children']['shipping-address-fieldset']['children']['shipping_firstname_jp'] = [
            'component' => 'Magento_Ui/js/form/element/abstract',
            'config' => [
                'customScope' => 'shippingAddress.extension_attributes',
                'template' => 'ui/form/field',
                'elementTmpl' => 'ui/form/element/input',
                'options' => [],
                'additionalClasses' => 'col-mp mp-6'
            ],
            'dataScope' => 'shippingAddress.extension_attributes.shipping_firstname_jp',
            'label' => __('FirstNameJp'),
            'provider' => 'checkoutProvider',
            'visible' => true,
            'validation' => [
                'required-entry' => true,
                'max_text_length' => 255,
                'min_text_length' => 1
            ],
            'customEntry' => null,
            'filterBy' => null,
            'sortOrder' => 3,
        ];

        $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
        ['shippingAddress']['children']['shipping-address-fieldset']['children']['shipping_lastname_jp'] = [
            'component' => 'Magento_Ui/js/form/element/abstract',
            'config' => [
                'customScope' => 'shippingAddress.extension_attributes',
                'template' => 'ui/form/field',
                'elementTmpl' => 'ui/form/element/input',
                'options' => [],
                'additionalClasses' => 'col-mp mp-6 mp-clear'
            ],
            'dataScope' => 'shippingAddress.extension_attributes.shipping_lastname_jp',
            'label' => __('LastNameJp'),
            'provider' => 'checkoutProvider',
            'visible' => true,
            'validation' => [
                'required-entry' => true,
                'max_text_length' => 255,
                'min_text_length' => 1
            ],
            'customEntry' => null,
            'filterBy' => null,
            'sortOrder' => 3,
        ];

        $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
        ['billingAddress']['children']['billing-address-fieldset']['children']['billing_firstname_jp'] = [
            'component' => 'Magento_Ui/js/form/element/abstract',
            'config' => [
                'customScope' => 'billingAddress.extension_attributes',
                'template' => 'ui/form/field',
                'elementTmpl' => 'ui/form/element/input',
                'options' => [],
                'additionalClasses' => 'col-mp mp-6'

            ],
            'dataScope' => 'billingAddress.extension_attributes.billing_firstname_jp',
            'label' => __('FirstNameJp'),
            'provider' => 'checkoutProvider',
            'visible' => true,
            'validation' => [
                'required-entry' => true,
                'max_text_length' => 255,
                'min_text_length' => 1
            ],
            'customEntry' => null,
            'filterBy' => null,
            'sortOrder' => 3,
        ];

        $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
        ['billingAddress']['children']['billing-address-fieldset']['children']['billing_lastname_jp'] = [
            'component' => 'Magento_Ui/js/form/element/abstract',
            'config' => [
                'customScope' => 'billingAddress.extension_attributes',
                'template' => 'ui/form/field',
                'elementTmpl' => 'ui/form/element/input',
                'options' => [],
                'additionalClasses' => 'col-mp mp-6'
            ],
            'dataScope' => 'billingAddress.extension_attributes.billing_lastname_jp',
            'label' => __('LastNameJp'),
            'provider' => 'checkoutProvider',
            'visible' => true,
            'validation' => [
                'required-entry' => true,
                'max_text_length' => 255,
                'min_text_length' => 1
            ],
            'sortOrder' => 3,
        ];

        $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
        ['shippingAddress']['children']['shipping-address-fieldset']['children']['lastname']['sortOrder'] = 0;

        $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
        ['billingAddress']['children']['billing-address-fieldset']['children']['lastname']['sortOrder'] = 0;

        $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
        ['shippingAddress']['children']['shipping-address-fieldset']['children']['firstname']['config']
        ['additionalClasses'] = 'col-mp mp-6';

        $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
        ['billingAddress']['children']['billing-address-fieldset']['children']['firstname']['config']
        ['additionalClasses'] = 'col-mp mp-6';

        // Drop the 2nd street line (address line 2) — core street multiline_count
        // is 2 globally; hide it on the JP checkout only.
        unset(
            $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
            ['shippingAddress']['children']['shipping-address-fieldset']['children']['street']['children'][1]
        );
        unset(
            $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
            ['billingAddress']['children']['billing-address-fieldset']['children']['street']['children'][1]
        );

        return $jsLayout;
    }
}
