/**
 * Exposes the server-rendered cross-sell HTML (customer-data section
 * "minicart-crosssell") to the minicart dropdown template.
 *
 * The markup is bound with `bindHtml` rather than `html` so that Magento's
 * declarative initialisation runs over it — without that the add-to-cart forms
 * would never get the catalogAddToCart widget and would submit as full page
 * posts.
 */
define([
    'jquery',
    'ko',
    'uiComponent',
    'Magento_Customer/js/customer-data',
    'mage/cookies'
], function ($, ko, Component, customerData) {
    'use strict';

    return Component.extend({
        /** @inheritdoc */
        initialize: function () {
            this._super();

            this.section = customerData.get('minicart-crosssell');
            this.cart = customerData.get('cart');

            this.crosssellHtml = ko.computed(function () {
                var data = this.section();

                return this.withFormKey((data && data.minicart_crosssell) || '');
            }, this);

            return this;
        },

        /**
         * The section HTML is cached in localStorage, so it cannot carry a form
         * key — it would be stale the moment the session rotates. Inject the
         * current one from the cookie instead.
         *
         * @param {String} html
         * @return {String}
         */
        withFormKey: function (html) {
            var formKey = $.mage.cookies.get('form_key');

            if (!html || !formKey) {
                return html;
            }

            return html.replace(
                /<\/form>/g,
                '<input type="hidden" name="form_key" value="' + formKey + '"></form>'
            );
        },

        /**
         * The section is rendered from the quote, so an empty cart means no
         * cross-sells — skip the binding entirely in that case.
         *
         * @return {Boolean}
         */
        hasItems: function () {
            var cart = this.cart();

            return !!(cart && cart.items && cart.items.length > 0);
        }
    });
});
