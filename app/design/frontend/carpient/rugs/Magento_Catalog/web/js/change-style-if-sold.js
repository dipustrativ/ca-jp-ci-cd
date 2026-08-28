/**
 * Copyright © 2020 eValent Group AB. All rights reserved.
 * See COPYING.txt for license details.
 */
/*jshint browser:true jquery:true*/

define([
    'jquery'
], function($) {
    'use strict';

    return function(config, element) {
        if ($(element).hasClass(config.outOfStockClass)) {
            $(config.priceElement).addClass(config.priceSoldClass);
        }
    }
});