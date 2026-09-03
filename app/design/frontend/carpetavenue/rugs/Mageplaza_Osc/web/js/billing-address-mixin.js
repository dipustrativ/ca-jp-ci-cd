/**
 * Force "billing and delivery address are the same" checkbox checked by default.
 */
define([
    'Magento_Checkout/js/model/quote',
    'Mageplaza_Osc/js/model/osc-data'
], function (quote, oscData) {
    'use strict';

    var mixin = {
        afterResolveDocument: function () {
            this._super();

            if (!quote.isVirtual()) {
                oscData.setData('same_as_shipping', true);
                this.isAddressSameAsShipping(true);
                this.useShippingAddress();
            }
        }
    };

    return function (target) {
        return target.extend(mixin);
    };
});
