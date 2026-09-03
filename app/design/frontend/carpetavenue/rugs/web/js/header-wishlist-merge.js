/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * Merge the header wishlist UI into the single heart icon.
 *
 * The header renders two wishlist entries: the core heart link
 * (.customer-account-header .link.wishlist > a, navigates to /wishlist/) and
 * BelVG GuestWishlist's own "My Wish List" link (#wishlist-link, opens the
 * mini-wishlist modal). We hide the BelVG text link (see _extend.less) and make
 * the heart icon open the same modal instead of navigating.
 *
 * #wishlist-link's click is delegated on document.body by the belvg.guestwishlist
 * widget, so triggering it works even while the element is hidden.
 */
define(['jquery', 'domReady!'], function ($) {
    'use strict';

    var HEART = '.customer-account-header .link.wishlist > a',
        MODAL_LINK = '#wishlist-link';

    $(document).on('click', HEART, function (event) {
        if ($(MODAL_LINK).length) {
            event.preventDefault();
            $(MODAL_LINK).trigger('click');
        }
    });
});
