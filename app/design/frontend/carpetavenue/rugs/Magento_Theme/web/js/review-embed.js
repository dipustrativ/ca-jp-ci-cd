/**
 * Height relay for the embedded review form.
 *
 * A cross-origin frame cannot be measured from the parent, so the form reports its own height.
 * The origin check is the whole security of this file: postMessage reaches any listener on the
 * page, so without it any framed third party could drive the layout of a page carrying an
 * invite token.
 */
define([], function () {
    'use strict';

    var MAX_HEIGHT = 5000,
        GAP = 16;

    return function (config, element) {
        var iframe = element.querySelector('[data-review-frame]'),
            origin = config && config.origin;

        // A listener that cannot verify its sender is worse than no listener; the CSS fallback
        // height still applies.
        if (!iframe || !origin) {
            return;
        }

        // Ask, rather than only wait to be told: this module loads asynchronously, so the
        // form's own load-time message is usually sent before anything here can receive it,
        // leaving the first render stuck at the fallback height.
        function requestHeight() {
            if (iframe.contentWindow) {
                iframe.contentWindow.postMessage({type: 'review-height-request'}, origin);
            }
        }

        // The frame cannot scroll its parent, and after submit the thank-you page is far
        // shorter than the form: the frame shrinks, the footer slides up, and the customer is
        // left looking at the footer. The bar that goes position:fixed here is .sticky.wrapper
        // (web/js/sticky-header.js pins it); .page-header is taller than that bar and would
        // leave a gap above the message.
        function scrollToFrame() {
            var nav = document.querySelector('.sticky.wrapper'),
                offset = nav ? nav.getBoundingClientRect().height : 0;

            window.scrollTo({
                top: iframe.getBoundingClientRect().top + window.pageYOffset - offset - GAP,
                behavior: 'auto'
            });
        }

        iframe.addEventListener('load', requestHeight);
        window.addEventListener('resize', requestHeight);
        requestHeight();

        window.addEventListener('message', function (event) {
            var height;

            if (event.origin !== origin || event.source !== iframe.contentWindow) {
                return;
            }

            if (!event.data) {
                return;
            }

            // Announced just before the shorter height. Scrolling before the shrink is safe:
            // the frame's top edge does not move when it shrinks, so the target is the same
            // either way.
            if (event.data.type === 'review-scroll') {
                scrollToFrame();

                return;
            }

            if (event.data.type !== 'review-height') {
                return;
            }

            height = parseInt(event.data.height, 10);

            if (height > 0) {
                iframe.style.height = Math.min(height, MAX_HEIGHT) + 'px';
            }
        });
    };
});
