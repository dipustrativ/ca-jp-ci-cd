/**
 * Copyright © CarpetAvenue. All rights reserved.
 * Pins the header wrapper to the top of the viewport on scroll.
 */
require(['jquery'], function ($) {
    'use strict';

    var $sticky = $('.sticky.wrapper');

    if (!$sticky.length) {
        return;
    }

    var stickyClass = 'sticky-pin',
        stickyPos = $sticky.offset().top;

    function toggleSticky() {
        if ($(window).scrollTop() >= stickyPos) {
            $sticky.addClass(stickyClass);
        } else {
            $sticky.removeClass(stickyClass);
        }
    }

    $sticky.after('<div class="jumps-prevent"></div>');

    function preventJump() {
        var height = $sticky.innerHeight();
        $sticky.css({ 'margin-bottom': '-' + height + 'px' });
        $sticky.next().css({ 'padding-top': height + 'px' });
    }

    toggleSticky();
    preventJump();

    $(window).on('scroll', toggleSticky);
    $(window).on('resize', preventJump);
});
