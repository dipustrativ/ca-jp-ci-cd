/**
 * @api
 */
define([
    'Magento_Checkout/js/model/quote',
    'jquery'
], function (quote, $) {
    'use strict';

    return function (shippingAddress) {
        if (shippingAddress['extension_attributes'] === undefined) {
            shippingAddress['extension_attributes'] = {};
        }

        shippingAddress['extension_attributes']['shipping_firstname_jp'] = $('[name="extension_attributes[shipping_firstname_jp]"]').val();
        shippingAddress['extension_attributes']['shipping_lastname_jp'] = $('[name="extension_attributes[shipping_lastname_jp]"]').val();
        shippingAddress['extension_attributes']['billing_firstname_jp'] = $('[name="extension_attributes[billing_firstname_jp]"]').val();
        shippingAddress['extension_attributes']['billing_lastname_jp'] = $('[name="extension_attributes[billing_lastname_jp]"]').val();

        quote.shippingAddress(shippingAddress);
    };
});
