define([
    'jquery',
    'underscore',
    'Magento_Checkout/js/model/quote',
    ],
    function ($, _,quote) {
    'use strict';

    var mixin = {
        validate: function () {
            if (!quote.paymentMethod()) {
                this.errorValidationMessage($.mage.__('Please specify a payment method.'));
                window.scrollTo({top: 0, behavior: 'smooth'});
                return false;
            }

            return true;
        }
    };

    return function (target) { // target == Result that Magento_Ui/.../columns returns.
        return target.extend(mixin); // new result that all other modules receive
    };
})
