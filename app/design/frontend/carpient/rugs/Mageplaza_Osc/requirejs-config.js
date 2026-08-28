/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

var config = {
    config: {
        mixins: {
            'Mageplaza_Osc/js/view/summary/item/details': {
                'Mageplaza_Osc/js/checkout-summary-mixin': true
            },
            'Mageplaza_Osc/js/view/shipping': {
                'Mageplaza_Osc/js/shipping-mixin': true
            },
            'Mageplaza_Osc/js/view/payment': {
                'Mageplaza_Osc/js/payment-mixin': true
            },
            'Mageplaza_Osc/js/view/billing-address': {
                'Mageplaza_Osc/js/billing-address-mixin': true
            }
        }
    }
};
