/**
 * Mageplaza
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the mageplaza.com license that is
 * available through the world-wide-web at this URL:
 * https://www.mageplaza.com/LICENSE.txt
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Mageplaza
 * @package     Mageplaza_Osc
 * @copyright   Copyright (c) Mageplaza (https://www.mageplaza.com/)
 * @license     https://www.mageplaza.com/LICENSE.txt
 */

define(
    [
        'Magento_Checkout/js/model/totals',
        'Magento_Checkout/js/model/quote',
        'Mageplaza_Osc/js/model/resource-url-manager',
        'Mageplaza_Osc/js/model/discount-payment-method'
    ],
    function (totals,
              quote,
              resourceUrlManager,
              discountPaymentMethod) {
        'use strict';

        return function (paymentData) {
            totals.isLoading(true);

            if (paymentData && paymentData.hasOwnProperty('__disableTmpl')) {
                delete paymentData.__disableTmpl;
            }

            var payload;
            if (paymentData.hasOwnProperty('title')) {
                paymentData = {
                    additional_data: null,
                    method: paymentData.method,
                    po_number: null,
                }
            }

            payload = {
                cartId: quote.getQuoteId(),
                paymentMethod: paymentData,
                billingAddress: quote.billingAddress()
            };

            return discountPaymentMethod(resourceUrlManager.getUrlForDiscountPaymentMethod(quote), payload);
        };
    }
);
