/**
 * Mageplaza
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Mageplaza.com license that is
 * available through the world-wide-web at this URL:
 * https://www.mageplaza.com/LICENSE.txt
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Mageplaza
 * @package     Mageplaza_Osc
 * @copyright   Copyright (c) Mageplaza (https://www.mageplaza.com/)
 * @license     https://www.mageplaza.com/LICENSE.txt
 */

var config = {};
if (typeof window.oscRoute !== 'undefined' && window.location.href.indexOf(window.oscRoute) !== -1) {
    config = {
        paths: {
            socialPopupForm: 'Mageplaza_Osc/js/social-login-popup'
        },
        map: {
            '*': {
                'Magento_Checkout/js/model/shipping-rate-service': 'Mageplaza_Osc/js/model/shipping-rate-service',
                'Magento_Checkout/js/model/shipping-rates-validator': 'Mageplaza_Osc/js/model/shipping-rates-validator',
                'Magento_CheckoutAgreements/js/model/agreements-assigner': 'Mageplaza_Osc/js/model/agreements-assigner',
                'Magento_Paypal/js/in-context/express-checkout-smart-buttons': 'Mageplaza_Osc/js/in-context/express-checkout-smart-buttons',
                'Magento_SalesRule/js/action/select-payment-method-mixin': 'Mageplaza_Osc/js/action/select-payment-method-mixin'
            },
            'Mageplaza_Osc/js/model/shipping-rates-validator': {
                'Magento_Checkout/js/model/shipping-rates-validator': 'Magento_Checkout/js/model/shipping-rates-validator'
            },
            'Magento_Checkout/js/model/shipping-save-processor/default': {
                'Magento_Checkout/js/model/full-screen-loader': 'Mageplaza_Osc/js/model/osc-loader'
            },
            'Magento_Checkout/js/action/set-billing-address': {
                'Magento_Checkout/js/model/full-screen-loader': 'Mageplaza_Osc/js/model/osc-loader'
            },
            'Magento_SalesRule/js/action/set-coupon-code': {
                'Magento_Checkout/js/model/full-screen-loader': 'Mageplaza_Osc/js/model/osc-loader/discount'
            },
            'Magento_SalesRule/js/action/cancel-coupon': {
                'Magento_Checkout/js/model/full-screen-loader': 'Mageplaza_Osc/js/model/osc-loader/discount'
            },
            'Mageplaza_Osc/js/model/osc-loader': {
                'Magento_Checkout/js/model/full-screen-loader': 'Magento_Checkout/js/model/full-screen-loader'
            }
        },
        config: {
            mixins: {
                'PayPal_Braintree/js/view/payment/method-renderer/paypal': {
                    'Mageplaza_Osc/js/view/payment/method-renderer/braintree-paypal-mixins': true
                },
                'PayPal_Braintree/js/view/payment/adapter': {
                    'Mageplaza_Osc/js/view/payment/braintree-adapter-mixin': true
                },
                'Magento_Checkout/js/action/place-order': {
                    'Mageplaza_Osc/js/action/place-order-mixins': true
                },
                'Magento_Paypal/js/action/set-payment-method': {
                    'Mageplaza_Osc/js/model/paypal/set-payment-method-mixin': true
                },
                'Magento_InventoryInStorePickupFrontend/js/view/store-pickup': {
                    'Mageplaza_Osc/js/view/store-pickup-mixins': true
                },
                'Magento_Catalog/js/catalog-add-to-cart':{
                    'Mageplaza_Osc/js/catalog-add-to-cart-mixin':true
                }
            }
        }
    };

    if (window.isEnableAmazonPayCv2 === 1) {
        config.config.mixins['Mageplaza_Osc/js/view/shipping-address/address-renderer/default'] = {
            'Amazon_Pay/js/view/shipping-address/address-renderer/default': true
        };
    }

    if (window.location.href.indexOf('#') !== -1) {
        window.history.pushState("", document.title, window.location.pathname);
    }
}
