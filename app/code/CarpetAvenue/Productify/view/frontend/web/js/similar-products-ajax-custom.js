define([
    'jquery',
    'mage/apply/main'
], function ($, mageApply) {
    'use strict';
    return function (config) {
        var productId = config.productId;
        // Prefer the store-scoped URL built by getUrl() (carries the /<store>/ code
        // so the controller renders in the right store and __() translates). Fall
        // back to the store-less path for backward compatibility.
        var url = config.ajaxUrl || '/productify/similarto/ajax/product/' + productId;

        $.ajax({
            url: url,
            method: "GET",
            success: function (response) {
                $('body .catalog-product-similar-ajax').html(response);
                $("body .catalog-product-similar-ajax .lazy-load").lazyload({
                    effect : "fadeIn",
                    threshold: 1000
                });
                if (window.innerWidth <= 768) {
                    var $mobileBtnHolder = $('body .catalog-product-similar-ajax .widget_category-btn-holder--mobile');
                    if ($mobileBtnHolder.length) {
                        $mobileBtnHolder.insertAfter('body .catalog-product-similar-ajax');
                    }
                }
            }
        });
    }
});
