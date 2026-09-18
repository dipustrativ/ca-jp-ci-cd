/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Restores the card-form presentation the live site has under Adyen Web 5 and adds
 * the 続行 button, on top of Adyen 10 / Adyen Web 6.
 *
 * Adyen Web 6 dropped the secured-field placeholders and added a contextual hint
 * line under 有効期限 / セキュリティコード. Both are component props, so the form is
 * reconfigured rather than restyled.
 */
/*browser:true*/
/*global define*/
define([
    'jquery',
    'Mageplaza_Osc/js/model/shipping-save-processor/checkout'
], function ($, shippingSaveProcessor) {
    'use strict';

    // Adyen Web 5's ja-JP defaults, which the live storefront still renders.
    // Literals, not $t(): carpetavenue/rugs is a single-locale ja_JP theme.
    var placeholders = {
        cardNumber: '1234 5678 9012 3456',
        expiryDate: 'MM/YY',
        securityCodeThreeDigits: '3桁',
        securityCodeFourDigits: '4桁'
    };

    var mixin = {
        /**
         * Adyen 10's own getTitle() prefers the `scheme` name from the /paymentMethods
         * response, which Adyen localises to 「カード」. Adyen 9, which live runs, had no
         * such override. Go back to the Magento method title so payment/adyen_cc/title
         * is what the storefront shows.
         */
        getTitle: function () {
            return this.item.title;
        },

        buildComponentConfiguration: function () {
            var config = this._super();

            if (!config) {
                return config;
            }

            config.placeholders = placeholders;
            // Collapses the hint line. The element stays in the DOM with its
            // aria-describedby id, so screen readers still get it.
            config.showContextualElement = false;

            return config;
        },

        /**
         * The in-form Place Order button is hidden on one step checkout; the order is
         * placed from the sidebar. 続行 saves the shipping info and sends the customer
         * there once the card form validates.
         */
        proceedToSummary: function (item, event) {
            shippingSaveProcessor.saveShippingInformation();

            if ($(event.target).closest('form').validation('isValid')) {
                $('html, body').animate({
                    scrollTop: $('#co-place-order-area').offset().top
                }, 500);
            }
        }
    };

    return function (target) {
        return target.extend(mixin);
    };
});
