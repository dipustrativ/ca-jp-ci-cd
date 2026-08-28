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
    'mage/utils/wrapper',
    'jquery',
    'Mageplaza_Osc/js/action/set-checkout-information',
    'Magento_Checkout/js/model/payment/additional-validators',
    'braintreePayPalCheckout'
], function (wrapper,
             $,
             setCheckoutInformationAction,
             additionalValidators,
             paypalCheckout) {
    'use strict';
    return function (BraintreeAdapter) {
        BraintreeAdapter.setupPaypal = wrapper.wrapSuper(BraintreeAdapter.setupPaypal, function () {
            var self = this;

            if (this.config.paypalInstance) {
                fullScreenLoader.stopLoader(true);
                return;
            }

            paypalCheckout.create({
                client: this.clientInstance
            }, function (createErr, paypalCheckoutInstance) {
                if (createErr) {
                    self.showError($t("PayPal Checkout could not be initialized. Please contact the store owner."));
                    console.error('paypalCheckout error', createErr);
                    return;
                }

                var paypalPayment           = this.config.paypal,
                    onPaymentMethodReceived = this.config.onPaymentMethodReceived,
                    style                   = {
                        color: this.getColor(),
                        shape: this.getShape(),
                        layout: this.getLayout(),
                        size: this.getSize()
                    },
                    funding                 = {
                        allowed: [],
                        disallowed: []
                    };

                if (this.getLabel()) {
                    style.label = this.getLabel();
                }
                if (this.getBranding()) {
                    style.branding = this.getBranding();
                }
                if (this.getFundingIcons()) {
                    style.fundingicons = this.getFundingIcons();
                }

                if (this.config.offerCredit === true) {
                    paypalPayment.offerCredit = true;
                    style.label               = "credit";
                    style.color               = "darkblue";
                    style.layout              = "horizontal";
                    funding.allowed.push(paypal.FUNDING.CREDIT);
                } else {
                    paypalPayment.offerCredit = false;
                    funding.disallowed.push(paypal.FUNDING.CREDIT);
                }

                // Disabled function options
                var disabledFunding = this.getDisabledFunding();
                if (true === disabledFunding.card) {
                    funding.disallowed.push(paypal.FUNDING.CARD);
                }
                if (true === disabledFunding.elv) {
                    funding.disallowed.push(paypal.FUNDING.ELV);
                }

                // Render
                this.config.paypalInstance = paypalCheckoutInstance;
                var events                 = this.events;

                $('#' + this.config.buttonId).html('');
                paypal.Button.render({
                    env: this.getEnvironment(),
                    style: style,
                    commit: true,
                    funding: funding,
                    locale: this.config.paypal.locale,

                    payment: function () {
                        return paypalCheckoutInstance.createPayment(paypalPayment);
                    },

                    onCancel: function (data) {
                        console.log('checkout.js payment cancelled', JSON.stringify(data, 0, 2));

                        if (typeof events.onCancel === 'function') {
                            events.onCancel();
                        }
                    },

                    onError: function (err) {
                        self.showError($t("PayPal Checkout could not be initialized. Please contact the store owner."));
                        this.config.paypalInstance = null;
                        console.error('Paypal checkout.js error', err);

                        if (typeof events.onError === 'function') {
                            events.onError(err);
                        }
                    }.bind(this),

                    onClick: function (data) {
                        if (additionalValidators.validate()) {
                            setCheckoutInformationAction();

                            if (typeof events.onClick === 'function') {
                                events.onClick(data);
                            }
                        }
                    },

                    /**
                     * Pass the payload (and payload.nonce) through to the implementation's onPaymentMethodReceived method
                     * @param data
                     * @param actions
                     */
                    onAuthorize: function (data, actions) {
                        return paypalCheckoutInstance.tokenizePayment(data)
                        .then(function (payload) {
                            onPaymentMethodReceived(payload);
                        });
                    }
                }, '#' + this.config.buttonId).then(function () {
                    this.enableButton();
                    if (typeof this.config.onPaymentMethodError === 'function') {
                        this.config.onPaymentMethodError();
                    }
                }.bind(this)).then(function (data) {
                    if (typeof events.onRender === 'function') {
                        events.onRender(data);
                    }
                });
            }.bind(this));
        });

        return BraintreeAdapter;
    };
});
