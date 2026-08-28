/**
 * Copyright © CarpetAvenue. All rights reserved.
 * Floating scroll-to-top button.
 */
require(['jquery'], function ($) {
    'use strict';

    var added = false;

    $(document).on('scroll', function () {
        if ($(this).scrollTop() >= 250) {
            if (!added) {
                added = true;
                $('body').append(
                    '<button class="button-go_top primary" data-scroll-button="top"><i class="arrow up"></i></button>'
                );
            }
            $('.button-go_top').fadeIn();
        } else {
            $('.button-go_top').fadeOut();
        }
    });

    $('body').on('click', "[data-scroll-button='top']", function () {
        $('html, body').animate({ scrollTop: 0 }, 600);
    });
});
