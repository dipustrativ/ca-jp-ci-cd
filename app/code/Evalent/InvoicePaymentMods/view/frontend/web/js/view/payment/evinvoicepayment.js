/**
 *
 */
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'evinvoicepayment',
                component: 'Evalent_InvoicePaymentMods/js/view/payment/method-renderer/evinvoicepayment-method'
            }
        );
        return Component.extend({});
    }
);