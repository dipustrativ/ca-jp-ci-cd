/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * @api
 */
define(
    [
        'jquery',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/url-builder',
        'mage/storage',
        'Magento_Checkout/js/model/error-processor',
        'Magento_Customer/js/model/customer',
        'Mageplaza_Osc/js/model/osc-loader',
        'Magento_Checkout/js/action/get-payment-information',
        'Mageplaza_Osc/js/action/reload-order-summary'
    ],
    function ($,
              quote,
              urlBuilder,
              storage,
              errorProcessor,
              customer,
              oscLoader,
              getPaymentInformationAction,
              reloadOrderSummary
    ) {
        'use strict';
        var itemUpdateLoader = [],
            loadingSpeedConfig = window.loadingSpeedConfig;
        return function (messageContainer) {
            var serviceUrl,
                payload;
            if (loadingSpeedConfig && loadingSpeedConfig.billing_address_change.includes("1")) {
                itemUpdateLoader.push('payment');
            }
            if (loadingSpeedConfig && loadingSpeedConfig.billing_address_change.includes("2")) {
                itemUpdateLoader.push('total');
            }
            /**
             * Checkout for guest and registered customer.
             */
            if (!customer.isLoggedIn()) {
                serviceUrl = urlBuilder.createUrl('/guest-carts/:cartId/billing-address', {
                    cartId: quote.getQuoteId()
                });
                payload    = {
                    cartId: quote.getQuoteId(),
                    address: quote.billingAddress()
                };
            } else {
                serviceUrl = urlBuilder.createUrl('/carts/mine/billing-address', {});
                payload    = {
                    cartId: quote.getQuoteId(),
                    address: quote.billingAddress()
                };
            }
            if (loadingSpeedConfig && loadingSpeedConfig.billing_address_change.includes("1")) {
                oscLoader.startLoader(itemUpdateLoader);
                return storage.post(
                    serviceUrl, JSON.stringify(payload)
                ).done(
                    function () {
                        var deferred = $.Deferred();
                        getPaymentInformationAction(deferred);
                        $.when(deferred).done(function () {
                            oscLoader.stopLoader(itemUpdateLoader);
                        });
                    }
                ).fail(
                    function (response) {
                        errorProcessor.process(response, messageContainer);
                        oscLoader.stopLoader(itemUpdateLoader);
                    }
                );
            }else if (loadingSpeedConfig && loadingSpeedConfig.billing_address_change.includes("2")){
                reloadOrderSummary()
            }
        };
    }
);
