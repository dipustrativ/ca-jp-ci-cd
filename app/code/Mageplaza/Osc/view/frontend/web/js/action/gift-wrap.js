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

define(
    [
        'Magento_Checkout/js/model/quote',
        'Mageplaza_Osc/js/model/resource-url-manager',
        'mage/storage',
        'Magento_Checkout/js/model/error-processor',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/model/payment/method-converter',
        'Magento_Checkout/js/model/payment-service',
        'Magento_Checkout/js/model/shipping-service',
        'Mageplaza_Osc/js/model/osc-loader',
        'Mageplaza_Osc/js/model/osc-data'
    ],
    function (quote,
              resourceUrlManager,
              storage,
              errorProcessor,
              customer,
              methodConverter,
              paymentService,
              shippingService,
              oscLoader,
              oscData) {
        'use strict';

        var itemUpdateLoader = ['shipping', 'payment', 'total'],
            loadingSpeedConfig = window.loadingSpeedConfig,
            oscConfig = window.checkoutConfig.oscConfig.enableOscPro;

        return function (payload) {
            if (!customer.isLoggedIn()) {
                payload.cart_id = quote.getQuoteId();
            }
            if (loadingSpeedConfig && oscConfig){
                itemUpdateLoader = [];
            }
            if (loadingSpeedConfig && oscConfig && loadingSpeedConfig.refresh_page !== '1' &&
                loadingSpeedConfig.gift_wrap.includes("1")) {
                itemUpdateLoader = ['shipping', 'total'];
            }
            if (loadingSpeedConfig && oscConfig && loadingSpeedConfig.refresh_page !== '1' &&
                loadingSpeedConfig.gift_wrap.includes("2")) {
                itemUpdateLoader.push('total');
            }
            oscLoader.startLoader(itemUpdateLoader);

            return storage.post(
                resourceUrlManager.getUrlForGiftWrapInformation(quote),
                JSON.stringify(payload)
            ).done(
                function (response) {
                    if (response.redirect_url) {
                        window.location.href = response.redirect_url;
                        return;
                    }
                    const shouldUpdateData = !loadingSpeedConfig || loadingSpeedConfig.refresh_page === '1' || !oscConfig;
                    const shouldUpdateGiftWrap = shouldUpdateData || (loadingSpeedConfig.gift_wrap &&
                                                loadingSpeedConfig.gift_wrap.includes("2")) && response.shipping_methods && !quote.isVirtual();

                    if (shouldUpdateData) {
                        oscData.setData('is_use_gift_wrap', payload.is_use_gift_wrap);
                        quote.setTotals(response.totals);
                        shippingService.setShippingRates(response.shipping_methods);
                        paymentService.setPaymentMethods(methodConverter(response.payment_methods));
                    }

                    if (shouldUpdateGiftWrap) {
                        oscData.setData('is_use_gift_wrap', payload.is_use_gift_wrap);
                        quote.setTotals(response.totals);
                        shippingService.setShippingRates(response.shipping_methods);
                    }
                }
            ).fail(
                function (response) {
                    errorProcessor.process(response);
                }
            ).always(
                function () {
                    oscLoader.stopLoader(itemUpdateLoader);
                }
            );
        };
    }
);
