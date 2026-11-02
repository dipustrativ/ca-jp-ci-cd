/**
 * Product review drawer.
 *
 * Right-side slide-in panel built on Magento_Ui/js/modal/modal with a bespoke shell template.
 * The `type: 'reviews'` + `reviewsTpl` pair is how modal.js picks a custom shell - see
 * options[this.options.type + 'Tpl'] in vendor/magento/module-ui/view/base/web/js/modal/modal.js.
 *
 * It must not reuse type 'filter': the layered-navigation slide-out (CarpetAvenue_Productify)
 * owns modal-filter, so sharing it would tie this drawer to that modal's styling and lifecycle.
 * The shell template is Magento_Ui/web/templates/modal/modal-reviews.html in this theme.
 *
 * Nothing is fetched until the drawer is first opened. "Load more" appends the next page under the
 * reviews already shown; a filter or sort change replaces the list and starts again at page one.
 */
define([
    'jquery',
    'Magento_Ui/js/modal/modal',
    'text!ui/template/modal/modal-reviews.html'
], function ($, modal, drawerTpl) {
    'use strict';

    // Cards a load adds rise into place one after another (.ixpf-review.is-entering). The stagger
    // stops growing after a few cards: the rest start below the fold, and waiting on them would
    // only hold back what is already visible.
    var ENTER_STAGGER_MS = 50,
        ENTER_STAGGER_MAX = 5,
        // Matches the .ixpf-reviews-more opacity transition in _extend.less.
        MORE_LEAVE_MS = 200;

    return function (config, element) {
        var $root = $(element),
            $summary = $root.find('[data-ixpf-reviews-summary]'),
            $list = $root.find('[data-ixpf-reviews-list]'),
            $more = $root.find('[data-ixpf-reviews-more]'),
            $moreButton = $root.find('[data-ixpf-reviews-more-button]'),
            $error = $root.find('[data-ixpf-reviews-error]'),
            $photos = $root.find('[data-ixpf-reviews-photos]'),
            $withText = $root.find('[data-ixpf-reviews-withtext]'),
            $sort = $root.find('[data-ixpf-reviews-sort]'),
            $rating = $root.find('[data-ixpf-reviews-rating]'),
            loaded = false,
            loading = false,
            reloadPending = false,
            page = 1,
            pageCount = 0,
            moreTimer = null;

        modal({
            type: 'reviews',
            customTpl: 'reviews',
            reviewsTpl: drawerTpl,
            title: config.title,
            closeText: config.closeText,
            modalClass: 'ixpf-reviews-modal',
            responsive: true,
            innerScroll: true,
            buttons: []
        }, $root);

        // fade: the last page arrived through "Load more", so the button fades out (.is-leaving)
        // before it is hidden instead of vanishing while the new cards animate in.
        function renderMore(fade) {
            var last = page >= pageCount;

            clearTimeout(moreTimer);
            if (last && fade && !$more.prop('hidden')) {
                $more.addClass('is-leaving');
                moreTimer = setTimeout(function () {
                    $more.prop('hidden', true).removeClass('is-leaving');
                }, MORE_LEAVE_MS);
            } else {
                $more.removeClass('is-leaving').prop('hidden', last);
            }
        }

        // The class and delay come off again in the delegated animationend handler below.
        function animateIn($cards) {
            $cards.each(function (index) {
                this.style.animationDelay = Math.min(index, ENTER_STAGGER_MAX) * ENTER_STAGGER_MS + 'ms';
            }).addClass('is-entering');
        }

        // Reveal only the checkboxes this pool can use (Reviews::getFilterAvailability()). A box
        // left hidden is unchecked as well, so it can never filter the list.
        function renderFilters(filters) {
            var any = false;

            $root.find('[data-ixpf-reviews-filter]').each(function () {
                var $box = $(this),
                    show = Boolean(filters && filters[$box.attr('data-ixpf-reviews-filter')]);

                if (!show) {
                    $box.find('input').prop('checked', false);
                }
                $box.prop('hidden', !show);
                any = any || show;
            });
            $root.find('[data-ixpf-reviews-filters]').prop('hidden', !any);
        }

        // append: add the page under the current list ("Load more") instead of replacing it.
        // Only the button shows the wait then; dimming every review already read would suggest
        // they are about to change.
        function load(targetPage, append) {
            var loadingClass = append ? 'is-appending' : 'is-loading';

            if (loading) {
                // A filter or sort change made mid-request (the keyboard still reaches the
                // controls) runs once the request lands, rather than being dropped while the
                // control already shows it. A second "Load more" is simply ignored.
                reloadPending = reloadPending || !append;

                return;
            }
            loading = true;
            $error.attr('hidden', 'hidden');
            $root.addClass(loadingClass);
            $moreButton.attr('aria-busy', append ? 'true' : null);

            $.ajax({
                url: config.loadUrl,
                type: 'GET',
                dataType: 'json',
                data: {
                    product_id: config.productId,
                    page: targetPage,
                    sort: $sort.val(),
                    rating: $rating.val(),
                    has_photos: $photos.is(':checked') ? 1 : '',
                    with_text: $withText.is(':checked') ? 1 : ''
                }
            }).done(function (response) {
                var shown = append ? $list.children('.ixpf-review').length : 0,
                    hadFocus = $moreButton.is(':focus'),
                    $added;

                $list[append ? 'append' : 'html'](response.items_html);
                $added = $list.children('.ixpf-review').slice(shown);
                if (!append) {
                    // A new filter or sort starts at the top of the panel, not wherever the
                    // previous list ended.
                    $root.closest('.modal-inner-wrap').scrollTop(0);
                }
                // The first open swaps the skeleton for the list, which already reads as the
                // content arriving; "Load more" and a filter or sort change animate what they add.
                if (loaded) {
                    animateIn($added);
                }
                // The summary ignores the filters by design, but it also carries the total, so it
                // is refreshed on every load rather than only on first open.
                $summary.html(response.summary_html);
                renderFilters(response.filters);

                page = response.page;
                pageCount = response.page_count;
                loaded = true;
                renderMore(append);

                // The last page hides the button, which would drop keyboard focus to <body>.
                // Continue from the first review it added instead.
                if (append && hadFocus && page >= pageCount) {
                    $added.first().attr('tabindex', '-1').trigger('focus');
                }
            }).fail(function () {
                $error.removeAttr('hidden');
            }).always(function () {
                loading = false;
                $root.removeClass(loadingClass);
                $moreButton.removeAttr('aria-busy');

                if (reloadPending) {
                    reloadPending = false;
                    load(1);
                }
            });
        }

        // Magento's closeModal() defers clearing _has-modal from <body> to a transitionend on the
        // aside. That event does not fire reliably for this drawer - the opacity/visibility
        // transitions never settle - which leaves the page scroll-locked after the drawer shuts.
        // Watching the class covers every close path: button, overlay click and Escape.
        var aside = $root.closest('aside[data-role="modal"]')[0];

        if (aside && window.MutationObserver) {
            new window.MutationObserver(function () {
                if (!aside.classList.contains('_show') &&
                    !document.querySelector('aside[data-role="modal"]._show')) {
                    document.body.classList.remove('_has-modal');
                }
            }).observe(aside, {attributes: true, attributeFilter: ['class']});
        }

        $(document).on('click', '[data-ixpf-reviews-open]', function (event) {
            event.preventDefault();
            $root.modal('openModal');

            // Not while the first request is still out: that would queue a second page one.
            if (!loaded && !loading) {
                load(1);
            }
        });

        $moreButton.on('click', function () {
            if (page < pageCount) {
                load(page + 1, true);
            }
        });

        // Any filter or sort change replaces the list from page one - the pages already appended
        // belong to the old filter.
        $photos.add($withText).add($sort).add($rating).on('change', function () {
            load(1);
        });

        // Delegated: rows are injected after these handlers are bound. animationend bubbles, so
        // only the card's own animation ends its entrance.
        $list.on('animationend', '.ixpf-review.is-entering', function (event) {
            if (event.target === this) {
                $(this).removeClass('is-entering');
                this.style.animationDelay = '';
            }
        });

        $list.on('click', '[data-ixpf-show-original]', function () {
            var $button = $(this),
                $text = $button.closest('.ixpf-review').find('[data-ixpf-review-text]'),
                showingOriginal = $button.data('showing-original') === true;

            // attr(), not data(): jQuery's data() JSON-parses values that look like numbers or
            // objects, so a review body such as "5" or "{...}" would not come back as text.
            $text.text($button.attr(showingOriginal ? 'data-translated' : 'data-original'));
            $button.text($button.attr(showingOriginal ? 'data-label-original' : 'data-label-translated'));
            $button.data('showing-original', !showingOriginal);
        });

        // A click toggles every photo in the review between preview and expanded (Rugvista).
        $list.on('click', '[data-ixpf-review-photo]', function () {
            var row = $(this).closest('[data-ixpf-review-photos]')[0],
                expand = !row.classList.contains('is-expanded');

            $(row).toggleClass('is-expanded', expand)
                .find('[data-ixpf-review-photo]').attr('aria-expanded', String(expand));
            // Keep the clicked photo in view once the row has grown.
            row.scrollLeft = expand ? this.offsetLeft : 0;
        });
    };
});
