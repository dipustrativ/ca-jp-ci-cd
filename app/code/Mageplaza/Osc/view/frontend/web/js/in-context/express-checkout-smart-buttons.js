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
    'underscore',
    'Magento_Paypal/js/in-context/paypal-sdk',
    'Magento_Checkout/js/model/payment/additional-validators',
    'Mageplaza_Osc/js/action/set-checkout-information',
    'Magento_Checkout/js/action/select-payment-method',
    'domReady!'
], function ($, _, paypalSdk, additionalValidators, setCheckoutInformationAction, selectPaymentMethodAction) {
    'use strict';

    /**
     * Triggers beforePayment action on PayPal buttons
     *
     * @param {Object} clientConfig
     * @returns {Object} jQuery promise
     */
    function performCreateOrder(clientConfig) {
        var params = {
            'quote_id': clientConfig.quoteId,
            'customer_id': clientConfig.customerId || '',
            'form_key': clientConfig.formKey,
            button: clientConfig.button
        };

        return $.Deferred(function (deferred) {
            clientConfig.rendererComponent.beforePayment(deferred.resolve, deferred.reject).then(function () {
                $.post(clientConfig.getTokenUrl, params).done(function (res) {
                    clientConfig.rendererComponent.afterPayment(res, deferred.resolve, deferred.reject);
                }).fail(function (jqXHR, textStatus, err) {
                    clientConfig.rendererComponent.catchPayment(err, deferred.resolve, deferred.reject);
                });
            });
        }).promise();
    }

    /**
     * Triggers beforeOnAuthorize action on PayPal buttons
     * @param {Object} clientConfig
     * @param {Object} data
     * @param {Object} actions
     * @returns {Object} jQuery promise
     */
    function performOnApprove(clientConfig, data, actions) {
        var params = {
            paymentToken: data.orderID,
            payerId: data.payerID,
            quoteId: clientConfig.quoteId || '',
            customerId: clientConfig.customerId || '',
            'form_key': clientConfig.formKey
        };

        return $.Deferred(function (deferred) {
            clientConfig.rendererComponent.beforeOnAuthorize(deferred.resolve, deferred.reject, actions)
            .then(function () {
                $.post(clientConfig.onAuthorizeUrl, params).done(function (res) {
                    clientConfig.rendererComponent
                    .afterOnAuthorize(res, deferred.resolve, deferred.reject, actions);
                }).fail(function (jqXHR, textStatus, err) {
                    clientConfig.rendererComponent.catchOnAuthorize(err, deferred.resolve, deferred.reject);
                });
            });
        }).promise();
    }

    /**
     * @param scrollTop
     * @returns {*|jQuery}
     */
    function preparePlaceOrderPayPalExpress(scrollTop) {
        var scrollTop = (scrollTop !== undefined) ? scrollTop : true;
        var deferer = $.when(setCheckoutInformationAction());

        return scrollTop ? deferer.done(function () {
            $("body").animate({scrollTop: 0}, "slow");
        }) : deferer;
    }

    return function (clientConfig, element) {
        paypalSdk(clientConfig.sdkUrl).done(function (paypal) {
            paypal.Buttons({
                style: clientConfig.styles,

                /**
                 * onInit is called when the button first renders
                 * @param {Object} data
                 * @param {Object} actions
                 */
                onInit: function (data, actions) {
                    // clientConfig.rendererComponent.validate(actions);
                },

                /**
                 * Triggers beforePayment action on PayPal buttons
                 * @returns {Object} jQuery promise
                 */
                createOrder: function () {
                    if (additionalValidators.validate()) {
                        return performCreateOrder(clientConfig);
                    }
                },

                /**
                 * Triggers beforeOnAuthorize action on PayPal buttons
                 * @param {Object} data
                 * @param {Object} actions
                 */
                onApprove: function (data, actions) {
                    performOnApprove(clientConfig, data, actions);
                },

                /**
                 * Execute logic on Paypal button click
                 */
                onClick: function () {
                    if (additionalValidators.validate()) {
                        selectPaymentMethodAction(clientConfig.rendererComponent.getData());
                        preparePlaceOrderPayPalExpress().done(function () {
                            clientConfig.rendererComponent.onClick();
                        });
                    } else {
                        var offsetHeight = $(window).height() / 2,
                            errorMsgSelector = $('#maincontent .mage-error:visible:first').closest('.field');
                        errorMsgSelector = errorMsgSelector.length ? errorMsgSelector :
                            $('#maincontent .field-error:visible:first').closest('.field');

                        if (errorMsgSelector.length) {
                            if (errorMsgSelector.find('select').length) {
                                $('html, body').scrollTop(
                                    errorMsgSelector.find('select').offset().top - offsetHeight
                                );
                                errorMsgSelector.find('select').focus();
                            } else if (errorMsgSelector.find('input').length) {
                                $('html, body').scrollTop(
                                    errorMsgSelector.find('input').offset().top - offsetHeight
                                );
                                errorMsgSelector.find('input').focus();
                            }
                        } else if ($('.message-error:visible').length) {
                            $('html, body').scrollTop(
                                $('.message-error:visible:first').closest('div').offset().top - offsetHeight
                            );
                        }
                    }
                },

                /**
                 * Process cancel action
                 * @param {Object} data
                 * @param {Object} actions
                 */
                onCancel: function (data, actions) {
                    clientConfig.rendererComponent.onCancel(data, actions);
                },

                /**
                 * Process errors
                 *
                 * @param {Error} err
                 */
                onError: function (err) {
                    clientConfig.rendererComponent.onError(err);
                }
            }).render(element);
        });
    };
});
