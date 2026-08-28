/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Developify
 * @author    Strativ Magento Team
 *
 * Mageplaza OSC overrides the core email component's `listens.email`
 * ('emailHasChanged') with '', so typing the guest email never pushes it to
 * quote.guestEmail. Payment methods that place the order through the core
 * guest flow (e.g. Adyen) then fail server-side with "email is required".
 *
 * This mixin re-syncs the entered email into quote.guestEmail / checkoutData
 * whenever it changes and is valid, without editing the vendor component.
 */
define([
    'jquery',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/checkout-data',
    'Magento_Customer/js/model/customer',
    'mage/validation'
], function ($, quote, checkoutData, customer) {
    'use strict';

    return function (Email) {
        return Email.extend({
            /**
             * @returns {Object}
             */
            initObservable: function () {
                this._super();

                if (!customer.isLoggedIn()) {
                    this.syncGuestEmail(this.email());
                    this.email.subscribe(this.syncGuestEmail.bind(this));
                }

                return this;
            },

            /**
             * Push a valid guest email into the quote so the core place-order
             * flow (Adyen, etc.) receives it.
             *
             * @param {String} value
             */
            syncGuestEmail: function (value) {
                if (!value || !$.validator.methods['validate-email'].call(this, value)) {
                    return;
                }

                quote.guestEmail = value;
                checkoutData.setValidatedEmailValue(value);
                checkoutData.setInputFieldEmailValue(value);
            }
        });
    };
});
