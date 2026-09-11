/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * AJAX layered navigation: intercepts filter link clicks, fetches the target
 * URL (a plain GET of the same page, so responses stay FPC-cacheable), swaps
 * the main column content and pushes the URL into history.
 *
 * Two modes:
 *  - instant (default): each filter click applies immediately.
 *  - batch  (batchApply): filter clicks only STAGE a selection and refresh a
 *    live result count in the "Show" button (.am-items); the staged selection
 *    is applied only when the shopper clicks Show (.am-apply-close). The staged
 *    selection is expressed as query params on the filter-free baseUrl, which
 *    the layer reads in both plain and SEO URL modes.
 *
 * After each swap it triggers:
 *  - 'contentUpdated'    → Magento re-applies data-mage-init / x-magento-init
 *  - 'collectionUpdated' → Lof_AjaxScroll destroys and re-binds its IAS instance
 */
define([
    'jquery',
    'jquery-ui-modules/widget'
], function ($) {
    'use strict';

    $.widget('carpetavenue.filterAjax', {
        options: {
            filterBlock: '.block.filter',
            mainColumn: '.column.main',
            linkSelectors: '.block.filter .am-filter-link[href]',
            batchApply: false,
            baseUrl: '',
            pageParam: 'p',
            categoryParam: 'cat',
            countSource: '.toolbar-number',
            countTarget: '.am-items',
            countDelay: 300,
            timeout: 20000
        },

        _create: function () {
            this._isLoading = false;
            this._onPopState = this._restoreState.bind(this);
            window.addEventListener('popstate', this._onPopState);

            if (this.options.batchApply) {
                this._staged = {};
                this._syncStagedFromDom();

                $(document)
                    .on('click.carpFilterAjax', '.am-filter-link[data-req-var]', this._onStageOption.bind(this))
                    // Removing an applied filter / clearing all applies at once
                    // (query URL) — no need to also press "Show".
                    .on('click.carpFilterAjax', '.am-chip-remove[data-req-var]', this._onRemoveApply.bind(this))
                    .on('click.carpFilterAjax', '.filter-clear', this._onClearApply.bind(this))
                    .on('click.carpFilterAjax', '.am-apply-close, .am-apply-filters', this._onApplyStaged.bind(this));
            } else {
                $(document).on('click.carpFilterAjax', this.options.linkSelectors, this._interceptClick.bind(this));
            }
        },

        _destroy: function () {
            $(document).off('click.carpFilterAjax');
            window.removeEventListener('popstate', this._onPopState);
        },

        /* ----------------------------- instant mode ---------------------- */

        _interceptClick: function (event) {
            var href = event.currentTarget.getAttribute('href');

            if (!href || event.ctrlKey || event.metaKey || event.shiftKey) {
                return;
            }

            event.preventDefault();
            this._applyUrl(href, true);
        },

        /* ------------------------------ batch mode ----------------------- */

        /**
         * Chips are the only complete record of the applied state — the
         * category filter drops its option links once a child is selected, so
         * reading those instead lost `cat` on the next Apply.
         */
        _syncStagedFromDom: function () {
            var staged = {};

            $('.am-chip-remove[data-req-var][data-value]').each(function () {
                var d = this.dataset;

                staged[d.reqVar] = staged[d.reqVar]
                    ? staged[d.reqVar] + ',' + d.value
                    : d.value;
            });

            this._staged = staged;
        },

        _onStageOption: function (event) {
            var d = event.currentTarget.dataset,
                reqVar = d.reqVar,
                value = d.value,
                multi = d.multi === '1',
                values = this._values(reqVar),
                idx = values.indexOf(value);

            event.preventDefault();

            if (multi) {
                if (idx >= 0) {
                    values.splice(idx, 1);
                } else {
                    values.push(value);
                }
            } else {
                values = idx >= 0 ? [] : [value];
            }

            this._setValues(reqVar, values);
            this._reflectOption(reqVar, value, values.indexOf(value) >= 0);
            this._refreshCount();
        },

        _onRemoveApply: function (event) {
            var d = event.currentTarget.dataset,
                values = this._values(d.reqVar),
                idx = values.indexOf(d.value);

            event.preventDefault();

            if (idx >= 0) {
                values.splice(idx, 1);
            }
            this._setValues(d.reqVar, values);
            this._applyUrl(this._buildStagedUrl(), true);
        },

        _onClearApply: function (event) {
            event.preventDefault();
            this._staged = {};
            this._applyUrl(this._buildStagedUrl(), true);
        },

        _onApplyStaged: function () {
            var url = this._buildStagedUrl();

            //  A category change is a different page, not a filtered view of this
            //  one. The breadcrumb, the page title and the category description all
            //  live outside the swapped column, and the server 301s the staged
            //  ?cat= URL onto the child category — which an AJAX fetch would follow
            //  silently, leaving the address bar on the pre-redirect URL. Hand it
            //  to the browser instead.
            if (this._staged[this.options.categoryParam]) {
                window.location.assign(url);

                return;
            }

            if (this._countUrl === url && this._countHtml) {
                try {
                    this._swap(this._countHtml);
                    window.history.pushState({carpFilterAjax: true}, '', url);
                    this._scrollToResults();

                    return;
                } catch (e) {
                    // fall through to a normal fetch
                }
            }
            this._applyUrl(url, true);
        },

        _values: function (reqVar) {
            return this._staged[reqVar] ? this._staged[reqVar].split(',').filter(Boolean) : [];
        },

        _setValues: function (reqVar, values) {
            if (values.length) {
                this._staged[reqVar] = values.join(',');
            } else {
                delete this._staged[reqVar];
            }
        },

        /**
         * Called by the price slider widget so a dragged range stages instead
         * of applying instantly.
         */
        stagePrice: function (from, to, requestVar, min, max) {
            var reqVar = requestVar || 'price';

            if (from <= min && to >= max) {
                delete this._staged[reqVar];
            } else {
                this._staged[reqVar] = from + '-' + to;
            }
            this._refreshCount();
        },

        _reflectOption: function (reqVar, value, on) {
            $('.am-filter-link[data-req-var="' + reqVar + '"]').filter(function () {
                return this.dataset.value === value;
            }).each(function () {
                $(this).closest('.am-filter-item').toggleClass('-selected', on);
                this.setAttribute('aria-checked', on ? 'true' : 'false');
            });
        },

        _buildStagedUrl: function () {
            var url = new URL(this.options.baseUrl || window.location.href, window.location.origin),
                staged = this._staged;

            Object.keys(staged).forEach(function (key) {
                if (staged[key]) {
                    url.searchParams.set(key, staged[key]);
                }
            });
            url.searchParams.delete(this.options.pageParam);

            return url.toString();
        },

        _refreshCount: function () {
            var self = this;

            clearTimeout(this._countTimer);
            this._countTimer = setTimeout(function () {
                self._fetchCount();
            }, this.options.countDelay);
        },

        _fetchCount: function () {
            var self = this,
                url = this._buildStagedUrl();

            if (this._countXhr) {
                this._countXhr.abort();
            }
            this._countXhr = $.ajax({
                url: url,
                type: 'GET',
                dataType: 'html',
                timeout: this.options.timeout,
                cache: true
            }).done(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html'),
                    nums = doc.querySelectorAll(self.options.countSource),
                    count = 0;

                self._countHtml = html;
                self._countUrl = url;

                // Grand total is the largest toolbar-number — robust against
                // locale word-order (JP renders "1104件中 1 - 50", total first;
                // EN "1-50 of 1104", total last). Taking .last() read the "to"
                // value (50/100 = page size) instead of the total.
                nums.forEach(function (node) {
                    var n = parseInt(node.textContent.replace(/[^0-9]/g, ''), 10);

                    if (!isNaN(n) && n > count) {
                        count = n;
                    }
                });
                self._updateCount(count);
            });
        },

        _updateCount: function (count) {
            $(this.options.countTarget).text('(' + count + ')');
        },

        /* -------------------------------- shared ------------------------- */

        _restoreState: function () {
            this._applyUrl(window.location.href, false);
        },

        /**
         * @param {String} url
         * @param {Boolean} pushState
         */
        _applyUrl: function (url, pushState) {
            var self = this;

            if (this._isLoading) {
                // Don't drop requests that arrive mid-flight (e.g. the price
                // slider firing again while the previous swap loads) — remember
                // the newest one and apply it when the current request settles.
                this._queuedUrl = url;
                this._queuedPush = pushState;

                return;
            }
            this._isLoading = true;
            $('body').trigger('processStart');

            $.ajax({
                url: url,
                type: 'GET',
                dataType: 'html',
                timeout: this.options.timeout,
                cache: true
            }).done(function (html) {
                try {
                    self._swap(html);

                    if (pushState) {
                        window.history.pushState({carpFilterAjax: true}, '', url);
                    }
                    self._scrollToResults();
                } catch (e) {
                    window.location.assign(url);
                }
            }).fail(function () {
                // Never leave the shopper stuck — degrade to a full navigation.
                window.location.assign(url);
            }).always(function () {
                var queuedUrl = self._queuedUrl,
                    queuedPush = self._queuedPush;

                self._isLoading = false;
                self._queuedUrl = null;
                $('body').trigger('processStop');

                if (queuedUrl) {
                    self._applyUrl(queuedUrl, queuedPush);
                }
            });
        },

        _swap: function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html'),
                newMain = doc.querySelector(this.options.mainColumn),
                currentMain = document.querySelector(this.options.mainColumn),
                $main;

            if (!newMain || !currentMain) {
                throw new Error('main column not found in AJAX response');
            }

            $main = $(currentMain);
            // .empty() lets jQuery drop handlers and data on the old tree; the
            // innerHTML write must stay raw, because jQuery's .html()/.append()
            // re-executes plain inline <script> blocks (the tile hover script
            // would stack a document listener on every apply).
            $main.empty();
            currentMain.innerHTML = newMain.innerHTML;

            document.title = doc.title;

            $main.trigger('contentUpdated');
            $('body').trigger('collectionUpdated');

            if (this.options.batchApply) {
                // The swapped markup reflects the newly-applied state — reset
                // the staged selection and the cached count preview to match.
                this._syncStagedFromDom();
                this._countHtml = null;
                this._countUrl = null;
            }
        },

        _scrollToResults: function () {
            var main = document.querySelector('#maincontent');

            if (main && main.getBoundingClientRect().top < 0) {
                main.scrollIntoView({behavior: 'smooth'});
            }
        }
    });

    return $.carpetavenue.filterAjax;
});
