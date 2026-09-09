/**
 * Copyright © CarpetAvenue. All rights reserved.
 * Floating scroll-to-top button. Not shown on product listing pages
 * (category / search results) — the floating filter pill owns that corner.
 */
require(['jquery'], function ($) {
    'use strict';

    //  Set by Catalog\Controller\Category\View and the two catalogsearch
    //  result layouts — i.e. exactly the pages carrying the filter pill.
    if ($('body').hasClass('page-products')) {
        return;
    }

    var $button = $('<button class="button-go_top primary" data-scroll-button="top"><i class="arrow up"></i></button>')
        .appendTo('body');

    //  Idempotent class write, CSS does the fade — a jQuery fadeIn/fadeOut
    //  pair plus a "currently visible" flag desyncs the moment anything else
    //  touches the element, and then never recovers.
    function toggleButton() {
        $button.toggleClass('is-visible', $(window).scrollTop() >= 250);
    }

    //  Once at init: the browser restores the scroll position on reload and
    //  anchors land mid-page, neither of which fires a scroll event.
    toggleButton();

    $(window).on('scroll', toggleButton);

    //  Toggle again on completion: animating scrollTop upwards fires no scroll
    //  event, so without this the button stays up after taking you to the top.
    $button.on('click', function () {
        $('html, body').animate({ scrollTop: 0 }, 600, toggleButton);
    });
});
