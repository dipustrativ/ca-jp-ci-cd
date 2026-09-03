define(['jquery',
        'underscore',
        'Magento_Checkout/js/model/quote',
        'Magento_Customer/js/model/customer',
        'uiRegistry',
        'Mageplaza_Osc/js/model/osc-data',
        'Mageplaza_Osc/js/action/reload-payment-method',
        'Mageplaza_Osc/js/model/address/auto-complete',
        'Mageplaza_Osc/js/model/shipping-rate-service'
    ],
    function ($, _,quote, customer, registry, oscData, reloadPaymentMethod) {
    'use strict';

    var reloaded_payments = false;

    var mixin = {
        initialize: function () {
            this._super();

            // var country = registry.get(
            //     'checkout.steps.shipping-step.shippingAddress.shipping-address-fieldset.country_id'
            // );
            //
            // if (typeof country !== "undefined" && country) {
            //     var countryFieldSelector = '[name="country_id"]';
            //
            //     function triggerCountryChangeWhenReady() {
            //         var el = $(countryFieldSelector);
            //
            //         if (el.length > 0 && el.val()) {
            //             Array.from(el).forEach(function(element) {
            //                 let countryValue = $(element).val();
            //
            //                 //-- Reset to trigger change
            //                 $(element).val('');
            //                 $(element).trigger('change');
            //
            //                 //-- Restore value and trigger change again
            //                 $(element).val(countryValue);
            //                 $(element).trigger('change');
            //             });
            //         } else {
            //             setTimeout(triggerCountryChangeWhenReady, 100);
            //         }
            //     }
            //
            //     //-- Start polling
            //     triggerCountryChangeWhenReady();
            // }
            
            oscData.setData('reloaded_payments', false);

            return this;
        },
        afterResolveDocument: function () {

            setTimeout(function () {
                var billingScope = registry.get("checkout.steps.shipping-step.billingAddress");

                if (billingScope.isAddressSameAsShipping() && !oscData.getData('reloaded_payments')) {

                    reloadPaymentMethod();
                    oscData.setData('reloaded_payments', true);

                }
            }, 1000);

            this._super();
        }
    };

    return function (target) { // target == Result that Magento_Ui/.../columns returns.
        return target.extend(mixin); // new result that all other modules receive
    };
})
