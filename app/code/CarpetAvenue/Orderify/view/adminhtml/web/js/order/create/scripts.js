define([
    'jquery',
    'Magento_Sales/order/create/scripts'
], function ($) {
    'use strict';

    function val(selector) {
        var $el = $(selector);
        return $el.length ? $el.val() : '';
    }

    function isChecked(selector) {
        var $el = $(selector);
        return $el.length && $el.is(':checked') ? '1' : '0';
    }

    function patchLoadArea() {
        if (!window.order || typeof window.order.loadArea !== 'function') return;
        if (window.order.__countryPatched) return;
        window.order.__countryPatched = true;

        var originalLoadArea = window.order.loadArea.bind(window.order);

        window.order.loadArea = function (areas, indicator, params) {
            params = params || {};
            var shipCountry = val('#order-shipping_address_country_id');
            var billCountry = val('#order-billing_address_country_id');
            var sameAsBilling = isChecked('#order-shipping_same_as_billing');

            // Add-only (won’t override anything Magento already set)
            if (shipCountry && typeof params.ca_ship_country === 'undefined') {
                params.ca_ship_country = shipCountry;
            }
            if (billCountry && typeof params.ca_bill_country === 'undefined') {
                params.ca_bill_country = billCountry;
            }
            if (typeof params.ca_shipping_same_as_billing === 'undefined') {
                params.ca_shipping_same_as_billing = sameAsBilling;
            }

            return originalLoadArea(areas, indicator, params);
        };
    }

    var orderVat = {
        init: function () {
            patchLoadArea();
            this.bindCountryChange();
        },

        bindCountryChange: function () {
            var self = this;
            $(document).on('change', '#order-shipping_address_country_id', function () {
                self.update();
            });
            $(document).on('change', '#order-billing_address_country_id', function () {
                self.update();
            });
        },

        update: function () {
            if (window.order && typeof window.order.loadArea === 'function') {
                window.order.loadArea(['shipping_method', 'totals', 'billing_method'], true);
            }
        }
    };

    return function () {
        orderVat.init();
    };
});
