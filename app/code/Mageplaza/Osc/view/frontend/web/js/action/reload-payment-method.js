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

define([
    'jquery',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/url-builder',
    'mage/storage',
    'Magento_Checkout/js/model/error-processor',
    'Magento_Customer/js/model/customer',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Checkout/js/action/get-payment-information',
    'Magento_CheckoutAgreements/js/model/agreements-assigner',
    'Mageplaza_Osc/js/model/payment-service'
], function ($,
             quote,
             urlBuilder,
             storage,
             errorProcessor,
             customer,
             fullScreenLoader,
             getPaymentInformationAction,
             agreementsAssigner,
             paymentService) {
    'use strict';

    return function () {
        var serviceUrl,
            payload,
            paymentData = quote.paymentMethod();

        agreementsAssigner(paymentData);


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
        paymentService.isLoading(true);

        return storage.post(
            serviceUrl, JSON.stringify(payload)
        ).done(
            function () {
                var deferred = $.Deferred();
                getPaymentInformationAction(deferred);
                $.when(deferred).done(function () {
                    fullScreenLoader.stopLoader();
                });
            }
        )
        .fail(function (response) {
            errorProcessor.process(response);
        }).always(function () {
            paymentService.isLoading(false);
        });
    };
});
