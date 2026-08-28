/**
 * Copyright © CarpetAvenue. All rights reserved.
 * Header behaviour: sticky wrapper on scroll + the mobile USP bar carousel.
 */
require(['jquery'], function ($) {
    'use strict';

    var $sticky = $('.sticky.wrapper');

    if (!$sticky.length) {
        return;
    }

    // Two phases, as on carpient.com: the moment the header would scroll away the
    // whole block pins (logo row + menu row + "Contact us"); COMPACT_AFTER more
    // pixels and the menu row is dropped so only the logo row stays pinned.
    var COMPACT_AFTER = 50,
        $spacer,
        stickyPos,
        naturalHeight;

    $sticky.after('<div class="jumps-prevent"></div>');
    $spacer = $sticky.next();

    // Measure only while unpinned: once pinned the wrapper is out of flow, and in
    // the compact phase it is shorter — reading either height would make the
    // spacer the wrong size and jump the page.
    function measure() {
        if ($sticky.hasClass('sticky-pin')) {
            return;
        }

        stickyPos = $sticky.offset().top;
        naturalHeight = $sticky.innerHeight();
        $sticky.css({ 'margin-bottom': '-' + naturalHeight + 'px' });
        $spacer.css({ 'padding-top': naturalHeight + 'px' });
    }

    function toggleSticky() {
        var top = $(window).scrollTop();

        $sticky.toggleClass('sticky-pin', top >= stickyPos);
        $sticky.toggleClass('sticky-compact', top >= stickyPos + COMPACT_AFTER);
    }

    measure();
    toggleSticky();

    $(window).on('scroll', toggleSticky);
    $(window).on('resize', function () {
        measure();
        toggleSticky();
    });
});

/**
 * USP bar carousel — below @screen__m the plum strip shows one USP at a time,
 * advanced by swipe or the chevron; at 768px and up it is a plain 3-across row.
 *
 * Slick is already registered globally by Evalent_MagicGallery (js + css), so
 * this only toggles it on the breakpoint. Slick's own `unslick` breakpoint is
 * not used: once unslicked it never re-inits, which would leave a phone stuck
 * with an overflowing row after a rotate to landscape and back.
 */
require(['jquery', 'slick', 'domReady!'], function ($) {
    'use strict';

    var $list = $('.carpient-usp-bar .carpient-usp-list').first(),
        mql = window.matchMedia('(max-width: 767px)');

    if (!$list.length || $list.children().length < 2) {
        return;
    }

    function sync() {
        var initialised = $list.hasClass('slick-initialized');

        if (mql.matches && !initialised) {
            $list.slick({
                slidesToShow: 1,
                infinite: true,
                dots: false,
                arrows: true,
                swipeToSlide: true,
                prevArrow: '<button type="button" class="carpient-usp-prev"></button>',
                nextArrow: '<button type="button" class="carpient-usp-next" aria-label="Next">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"' +
                    ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
                    '<polyline points="9 18 15 12 9 6"></polyline></svg></button>'
            });

            // Slick measures the list while it is still the desktop flex row, so
            // the first slide comes out too wide. Re-measure once the carousel
            // markup (and its `display: block`) is in place.
            $list.slick('setPosition');
        } else if (!mql.matches && initialised) {
            $list.slick('unslick');
        }
    }

    sync();

    if (typeof mql.addEventListener === 'function') {
        mql.addEventListener('change', sync);
    } else if (typeof mql.addListener === 'function') {
        mql.addListener(sync);
    }
});
