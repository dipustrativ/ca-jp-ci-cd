/**
 * Copyright © Carpient. All rights reserved.
 *
 * Reference-style filter bar (carpient.com): each attribute is a button that
 * opens an inline dropdown panel directly below it. Presentation only — the
 * option clicks, multiselect staging, live count and "Apply" are all handled
 * by CarpetAvenue_Productify's filter-ajax widget (batch mode), which
 * delegates from `document`, so this widget never touches filtering. It only
 * opens/closes panels.
 *
 * No stopPropagation anywhere: option / Apply clicks must keep bubbling to the
 * document-level filter-ajax handlers. The outside-click close instead checks
 * whether the click landed inside the filter block.
 */
define([
    'jquery',
    'jquery-ui-modules/widget'
], function ($) {
    'use strict';

    $.widget('carpient.filterDropdown', {
        _create: function () {
            var self = this,
                $el = this.element;

            this._mq = window.matchMedia('(max-width: 767px)');

            $el.on('click.carpientFD', '.carpient-filter__toggle', function (event) {
                var $group = $(this).closest('.carpient-filter__group'),
                    isOpen = $group.hasClass('-open');

                event.preventDefault();

                // Desktop panels overlay the page, so only one may be open at a
                // time. In the drawer every group is visible at once and each
                // one toggles on its own — collapsing the rest would fight the
                // expanded-by-default layout.
                if (!self._mq.matches) {
                    self._closeAll();
                }

                if (isOpen) {
                    self._close($group);
                } else {
                    self._open($group);
                }
            });

            // One shared, namespaced document handler (survives re-init after
            // an AJAX column swap — off before on so it never stacks).
            $(document)
                .off('click.carpientFDdoc')
                .on('click.carpientFDdoc', function (event) {
                    // The opener is excluded because on mobile it lives in the
                    // toolbar, i.e. outside .carpient-filter — without this the
                    // click that opens the drawer would immediately close it.
                    if (!$(event.target).closest('.carpient-filter, .carpient-filter__open').length) {
                        $('.carpient-filter__group.-open').each(function () {
                            self._close($(this));
                        });
                        self._drawer(false);
                    }
                })
                .off('keyup.carpientFDdoc')
                .on('keyup.carpientFDdoc', function (event) {
                    if (event.key === 'Escape') {
                        $('.carpient-filter__group.-open').each(function () {
                            self._close($(this));
                        });
                        self._drawer(false);
                    }
                });

            this._initMobile();
        },

        /**
         * Below @screen__m the bar turns into an off-canvas drawer opened from a
         * button that lives in the product toolbar, next to Sort By.
         * @private
         */
        _initMobile: function () {
            var self = this,
                $el = this.element,
                mq = window.matchMedia('(max-width: 767px)');

            $el.add(document).off('click.carpientFDdrawer');

            $(document).on('click.carpientFDdrawer', '.carpient-filter__open', function (event) {
                event.preventDefault();
                self._drawer(!$el.hasClass('-drawer-open'));
            }).on('click.carpientFDdrawer', '.carpient-filter__close', function (event) {
                event.preventDefault();
                self._drawer(false);
            }).on('click.carpientFDdrawer', '.carpient-filter__show', function () {
                // filter-ajax handles the apply (this button carries
                // .am-apply-close); we only dismiss the drawer.
                self._drawer(false);
            });

            /**
             * @param {Boolean} matches
             */
            // The listbox is the sort control at every breakpoint.
            self._buildSorter();

            function sync(matches) {
                if (matches) {
                    self._dockOpener();
                    self._expandAll();
                } else {
                    self._drawer(false);
                    self._undockOpener();
                    self._closeAll();
                }
            }

            sync(mq.matches);
            mq.addEventListener('change', function (event) {
                sync(event.matches);
            });
        },

        /**
         * Park the opener in the top toolbar so it renders beside the sorter.
         * Only our own button moves — the core sorter stays put, because
         * productListToolbarForm resolves it by descending from .toolbar-products.
         * @private
         */
        _dockOpener: function () {
            var $toolbar = $('.toolbar-products').first(),
                $opener = this.element.find('.carpient-filter__open');

            if (!$toolbar.length || !$opener.length) {
                return;
            }

            $toolbar.addClass('carpient-filter-toolbar');
            $opener.appendTo($toolbar);
        },

        /** @private */
        _undockOpener: function () {
            var $opener = $('.toolbar-products .carpient-filter__open');

            $('.toolbar-products').removeClass('carpient-filter-toolbar');

            if ($opener.length) {
                $opener.prependTo(this.element);
            }
        },

        /**
         * Replace the native sorter with a listbox matching the reference. The
         * <select> stays in the DOM as the source of truth — picking an option
         * sets its value and fires change, so productListToolbarForm still owns
         * the navigation and nothing here has to know about sort URLs.
         * @private
         */
        _buildSorter: function () {
            var $select = $('.toolbar-sorter .sorter-options'),
                $wrap = $('.toolbar-sorter'),
                items = '',
                $sort;

            if (!$select.length || $wrap.find('.carpient-sort').length) {
                return;
            }

            $select.find('option').each(function () {
                var $o = $(this);

                items += '<li><button type="button" class="carpient-sort__option' +
                    ($o.is(':selected') ? ' -active' : '') + '" data-value="' +
                    $o.attr('value') + '">' + $o.text().trim() + '</button></li>';
            });

            $sort = $(
                '<div class="carpient-sort">' +
                '<button type="button" class="carpient-sort__toggle" aria-expanded="false">' +
                '<span class="carpient-sort__value">' + $select.find('option:selected').text().trim() + '</span>' +
                '<svg class="carpient-sort__caret" width="10" height="7" viewBox="0 0 10 7" fill="none" aria-hidden="true">' +
                '<path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>' +
                '</svg></button>' +
                '<ul class="carpient-sort__list" hidden>' + items + '</ul>' +
                '</div>'
            ).appendTo($wrap);

            // Cue for the CSS to hide the native select — without JS it stays.
            $wrap.addClass('-has-listbox');

            $sort.on('click.carpientSort', '.carpient-sort__toggle', function (event) {
                event.preventDefault();
                event.stopPropagation();
                $sort.toggleClass('-open');
                $sort.find('.carpient-sort__list').prop('hidden', !$sort.hasClass('-open'));
                $(this).attr('aria-expanded', $sort.hasClass('-open') ? 'true' : 'false');
            }).on('click.carpientSort', '.carpient-sort__option', function (event) {
                event.preventDefault();
                $select.val($(this).data('value')).trigger('change');
            });

            $(document).off('click.carpientSortDoc').on('click.carpientSortDoc', function (event) {
                if (!$(event.target).closest('.carpient-sort').length) {
                    $('.carpient-sort').removeClass('-open')
                        .find('.carpient-sort__list').prop('hidden', true);
                }
            });
        },

        /**
         * The drawer opens with every block already expanded (verified against
         * the reference recording: closed at 50.9s, reopened at 57.7s with
         * Color and Shape open). Tapping a header still collapses it.
         * @private
         */
        _expandAll: function () {
            var self = this;

            this.element.find('.carpient-filter__group').each(function () {
                self._open($(this));
            });
        },

        /**
         * @param {Boolean} open
         * @private
         */
        _drawer: function (open) {
            if (!open && !this.element.hasClass('-drawer-open')) {
                return;
            }

            if (open) {
                // An outside click closes the drawer and every block with it,
                // so re-expand or the next open would come up all collapsed.
                this._expandAll();
            }

            this.element.toggleClass('-drawer-open', open);
            $('body').toggleClass('carpient-filter-drawer-open', open);
            $('.carpient-filter__open').attr('aria-expanded', open ? 'true' : 'false');
        },

        _closeAll: function () {
            var self = this;

            this.element.find('.carpient-filter__group.-open').each(function () {
                self._close($(this));
            });
        },

        _open: function ($group) {
            $group.addClass('-open');
            $group.children('.carpient-filter__toggle').attr('aria-expanded', 'true');
            $group.children('.carpient-filter__panel').prop('hidden', false);
        },

        _close: function ($group) {
            $group.removeClass('-open');
            $group.children('.carpient-filter__toggle').attr('aria-expanded', 'false');
            $group.children('.carpient-filter__panel').prop('hidden', true);
        },

        _destroy: function () {
            this._drawer(false);
            this.element.off('.carpientFD');
        }
    });

    return $.carpient.filterDropdown;
});
