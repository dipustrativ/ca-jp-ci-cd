define(
    [
        'Magento_Checkout/js/view/payment/default'
    ],
    function (Component) {
        'use strict';
        return Component.extend({
            defaults: {
                template: 'Evalent_InvoicePaymentMods/payment/evinvoicepayment'
            },
            getCheckaddress: function () {
                //Checkaddress: Check if Shipping anf billing must be the same address In path_In Core Table payment/evinvoicepayment/checkaddress.
                if (window.checkoutConfig.payment.evinvoicepayment.checkaddress == 1) {
                    return true;
                }
                else
                    return false;
            },
            getInstructions: function () {
                //Instructions: General instructions In path_In Core Table payment/evinvoicepayment/instructions.
                //with the text value in field.
                if (window.checkoutConfig.payment.evinvoicepayment.instructions) {
                    return window.checkoutConfig.payment.evinvoicepayment.instructions;
                }
            },
            getCheckaddressInstructions: function () {
                //Checkaddressinstructions: Shipping anf billing instructions In path In Core Table payment/evinvoicepayment/checkaddressinstructions
                //with the text value in field.
                if (window.checkoutConfig.payment.evinvoicepayment.checkaddressinstructions) {
                    return window.checkoutConfig.payment.evinvoicepayment.checkaddressinstructions;
                }
            }
        });
    }
);