define([
    'jquery',
    'mage/apply/main'
], function ($, mageApply) {
    'use strict';
    return function (config) {
        var productId = config.productId;
        var url = '/pricehistory/product/ajax/id/' + productId;

        $.ajax({
            url: url,
            method: "GET",
            success: function (response) {
                $('body .price-lowest-price-days').html(response)
            }
        })
    }
});
