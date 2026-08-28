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

    $search.on('click', '.block-title', function (e) {
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
});
