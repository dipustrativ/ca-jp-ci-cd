/**
 * Copyright © CarpetAvenue. All rights reserved.
 * Toggle the header search field open/closed from the magnifier icon.
 */
require(['jquery', 'domReady!'], function ($) {
    'use strict';

    var $search = $('.block-search');

    if (!$search.length) {
        return;
    }

    //  `.field.search > .label` is the magnifier inside the field. It closes the
    //  search on mobile, where the X trigger is hidden; on desktop CSS keeps it at
    //  `pointer-events: none`, so this handler never fires there. No breakpoint
    //  check needed here — the media query is the gate.
    $search.on('click', '.block-title, .field.search > .label', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $search.toggleClass('active');

        if ($search.hasClass('active')) {
            $search.find('#search').trigger('focus');
        }
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('.block-search').length) {
            $search.removeClass('active');
        }
    });

    //  A <label> is not keyboard-reachable, so with the X gone on mobile this is
    //  the only way out for keyboard users.
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            $search.removeClass('active');
        }
    });
});
