/*jshint browser:true jquery:true*/

define([
    'jquery'
], function($) {
    'use strict';

    return function(config, element) {

        $(element).on("click", function () {
            $(element).addClass(config.outOfStockClass);
        });
    }
});
