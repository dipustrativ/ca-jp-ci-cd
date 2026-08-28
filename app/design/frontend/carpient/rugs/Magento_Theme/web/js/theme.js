/**
 * Copyright © CarpetAvenue. All rights reserved.
 * Toggle the header search field open/closed from the magnifier icon.
 *
 * Open state is also flagged on <body> (search-open) so the header can hide the
 * logo/actions and dim the page while the search bar takes the whole row.
 */
require(['jquery', 'domReady!'], function ($) {
    'use strict';

    var $search = $('.block-search'),
        $body = $('body'),
        ESC = 27;

    if (!$search.length) {
        return;
    }

    function close() {
        $search.removeClass('active');
        $body.removeClass('search-open');
    }

    function open() {
        $search.addClass('active');
        $body.addClass('search-open');
        $search.find('#search').trigger('focus');
    }

    $search.on('click', '.block-title', function (e) {
        e.stopPropagation();

        if ($search.hasClass('active')) {
            close();
        } else {
            open();
        }
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('.block-search').length) {
            close();
        }
    });

    $(document).on('keydown', function (e) {
        if (e.keyCode === ESC && $search.hasClass('active')) {
            close();
        }
    });
});
