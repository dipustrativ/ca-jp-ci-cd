/**
 * Copyright © 2020 eValent Group AB. All rights reserved.
 * See COPYING.txt for license details.
 */
/*jshint browser:true jquery:true*/
define([
        'jquery'
    ],
    function ($) {
        "use strict";

        return function (config, element) {
            $(element).on('click', function (e) {
                $('.product-short_description').addClass('expanded');

                var container = $("html,body");
                var scrollTo = $('.product.attribute.description');
                var header = $('header');

                container.animate({
                    scrollTop: scrollTo.offset().top - header.height() - 40,
                    scrollLeft: 0
                }, 300);


                return false;
            });
        }

    }
);
