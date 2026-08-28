/**
 * Config Scope Hints — expand/collapse the overridden value under each scope row.
 *
 * The hint markup is injected server-side into config field comments, so the handler is
 * delegated from the document: it keeps working for fields revealed later (dependent
 * fields, collapsed groups).
 */
require([
    'jquery'
], function ($) {
    'use strict';

    function toggle(scope) {
        scope.toggleClass('open')
            .attr('aria-expanded', scope.hasClass('open') ? 'true' : 'false')
            .next('.override-value').toggleClass('visible');
    }

    $(document).on('click', '.overridden-hint-list .override-scope', function (event) {
        // Let the scope link navigate instead of toggling.
        if ($(event.target).closest('a').length) {
            return;
        }

        toggle($(this));
    });

    $(document).on('keydown', '.overridden-hint-list .override-scope', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        if ($(event.target).closest('a').length) {
            return;
        }

        event.preventDefault();
        toggle($(this));
    });
});
