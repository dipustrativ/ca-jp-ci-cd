/**
 * Copyright © CarpetAvenue. All rights reserved.
 * Mobile footer accordion: the Service / Carpient columns collapse below 768px.
 *
 * Column pick is `.footer-heading` — the contact column's CMS block uses <h3>,
 * the two link columns use <div class="footer-heading">, so that class already
 * selects exactly the two that should collapse. If a CMS block is re-authored
 * with a different heading tag, adjust here, not in the block.
 *
 * Markup and open/close state stay untouched above 768px; only the class hooks
 * are added, the collapsing itself is CSS (see _carpient_footer.less).
 */
require(['jquery', 'domReady!'], function ($) {
    'use strict';

    var mq = window.matchMedia('(max-width: 767px)'),
        $cols = $('.carpient-footer-col:has(.footer-heading)');

    if (!$cols.length) {
        return;
    }

    $cols.addClass('carpient-footer-col--toggle')
        .find('.footer-heading')
        .attr({ role: 'button', tabindex: 0, 'aria-expanded': 'false' });

    /**
     * @param {jQuery} $heading
     */
    function toggle($heading) {
        if (!mq.matches) {
            return;
        }

        var open = $heading.closest('.carpient-footer-col').toggleClass('is-open').hasClass('is-open');

        $heading.attr('aria-expanded', open ? 'true' : 'false');
    }

    $cols.on('click', '.footer-heading', function () {
        toggle($(this));
    }).on('keydown', '.footer-heading', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            toggle($(this));
        }
    });

    // Back to desktop: drop the open state so the columns render as a plain grid.
    mq.addEventListener('change', function (event) {
        if (!event.matches) {
            $cols.removeClass('is-open').find('.footer-heading').attr('aria-expanded', 'false');
        }
    });
});
