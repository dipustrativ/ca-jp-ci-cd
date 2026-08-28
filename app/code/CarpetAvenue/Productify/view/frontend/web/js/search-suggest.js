/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * Live search suggestions: fetches a rendered fragment from
 * /productify/search/suggest and drops it into the dropdown host. Also drives
 * the cycling typewriter placeholder.
 *
 * Bound to the header mini search and to the search-results page band.
 */
define([
    'jquery',
    'jquery-ui-modules/widget'
], function ($) {
    'use strict';

    var SKELETON_ROWS = 4,
        DESKTOP_MIN_WIDTH = 769;

    $.widget('productify.searchSuggest', {
        options: {
            url: '',
            destination: '',
            formSelector: '',
            minChars: 2,
            delay: 150,
            // Cycling placeholder. Empty list = keep the static placeholder.
            placeholders: [],
            typingSpeed: 100,
            deletingSpeed: 60,
            delayAfterTyped: 2400,
            delayAfterDeleted: 500,
            /** Desktop only — a phone keyboard covers the field anyway. */
            animateOnMobile: false
        },

        /** @private */
        _create: function () {
            this.$input = this.element;
            this.$destination = $(this.options.destination);
            this.$form = this.options.formSelector ? $(this.options.formSelector) : this.$input.closest('form');
            this.cache = {};
            /** Term the panel currently shows, or null when it shows nothing real. */
            this.rendered = null;
            this.xhr = null;
            this.timer = null;
            this.selected = -1;
            this.staticPlaceholder = this.$input.attr('placeholder') || '';

            if (!this.$destination.length) {
                return;
            }

            this._bind();
            this._startPlaceholderCycle();
        },

        /** @private */
        _bind: function () {
            var self = this;

            this.$input.on('input.suggest', function () {
                self._stopPlaceholderCycle();
                self._schedule();
            });

            this.$input.on('focus.suggest', function () {
                if (self.$destination.children().length) {
                    self._open();
                }
            });

            this.$input.on('keydown.suggest', function (event) {
                self._onKeyDown(event);
            });

            // Clear button, when the markup provides one.
            this.$form.on('click.suggest', '[data-role="suggest-clear"]', function (event) {
                event.preventDefault();
                self.$input.val('').trigger('focus');
                self._abort();
                self._close();
                self._startPlaceholderCycle();
            });

            $(document).on('click.suggest', function (event) {
                if (!$(event.target).closest(self.$destination).length &&
                    !$(event.target).closest(self.$input).length) {
                    self._close();
                }
            });
        },

        /** @private */
        _onKeyDown: function (event) {
            var keys = { esc: 27, up: 38, down: 40, enter: 13 },
                $items;

            if (event.keyCode === keys.esc) {
                this._close();
                return;
            }

            $items = this.$destination.find('[data-suggest-item]');

            if (!$items.length) {
                return;
            }

            if (event.keyCode === keys.down || event.keyCode === keys.up) {
                event.preventDefault();
                this.selected += event.keyCode === keys.down ? 1 : -1;

                if (this.selected >= $items.length) {
                    this.selected = 0;
                } else if (this.selected < 0) {
                    this.selected = $items.length - 1;
                }

                $items.removeClass('is-selected').eq(this.selected).addClass('is-selected');
                return;
            }

            // Enter on a highlighted row opens it; otherwise the form submits.
            if (event.keyCode === keys.enter && this.selected > -1) {
                event.preventDefault();
                window.location.href = $items.eq(this.selected).attr('href');
            }
        },

        /** @private */
        _schedule: function () {
            var self = this,
                query = $.trim(this.$input.val());

            clearTimeout(this.timer);

            if (query.length < this.options.minChars) {
                this._abort();
                this._close();
                return;
            }

            if (typeof this.cache[query] === 'string') {
                this.rendered = query;
                this._render(this.cache[query]);
                return;
            }

            this.timer = setTimeout(function () {
                self._fetch(query);
            }, this.options.delay);
        },

        /** @private */
        _fetch: function (query) {
            var self = this;

            this._abort();

            // Still typing the same term? Keep the panel that is up rather than
            // flashing grey rows on every letter. Anything else is stale content.
            if (!this.rendered || query.indexOf(this.rendered) !== 0) {
                this._renderSkeleton();
            }

            this.xhr = $.ajax({
                url: this.options.url,
                data: { q: query },
                dataType: 'html',
                cache: true,
                timeout: 8000
            }).done(function (html) {
                self.cache[query] = html;

                // Ignore a response the user has already typed past.
                if ($.trim(self.$input.val()) === query) {
                    self.rendered = query;
                    self._render(html);
                }
            }).fail(function (xhr, status) {
                if (status !== 'abort') {
                    self._close();
                }
            });
        },

        /** @private */
        _abort: function () {
            clearTimeout(this.timer);

            if (this.xhr) {
                this.xhr.abort();
                this.xhr = null;
            }
        },

        /** @private */
        _renderSkeleton: function () {
            var rows = '',
                i;

            for (i = 0; i < SKELETON_ROWS; i++) {
                rows += '<li class="productify-suggest__skeleton-row">' +
                    '<span class="productify-suggest__skeleton-thumb"></span>' +
                    '<span class="productify-suggest__skeleton-lines">' +
                    '<span></span><span></span><span></span>' +
                    '</span></li>';
            }

            this._render('<ul class="productify-suggest__skeleton">' + rows + '</ul>');
        },

        /** @private */
        _render: function (html) {
            this.selected = -1;
            this.$destination.html(html);

            if ($.trim(html) === '') {
                this._close();
                return;
            }

            this._open();
        },

        /** @private */
        _open: function () {
            this.$destination.addClass('is-open');
            this.$input.attr('aria-expanded', 'true');
            $('body').addClass('productify-suggest-open');
        },

        /** @private */
        _close: function () {
            this.rendered = null;
            this.$destination.removeClass('is-open');
            this.$input.attr('aria-expanded', 'false');
            $('body').removeClass('productify-suggest-open');
        },

        /**
         * Types each phrase one character at a time, deletes it, moves to the next.
         * @private
         */
        _startPlaceholderCycle: function () {
            var phrases = this.options.placeholders || [],
                reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches,
                narrow = window.innerWidth < DESKTOP_MIN_WIDTH;

            if (!phrases.length || reduced || (narrow && !this.options.animateOnMobile)) {
                return;
            }

            this.typing = { phrase: 0, chars: 0, deleting: false };
            this._tickPlaceholder();
        },

        /**
         * One character per tick; the wait until the next tick carries the pauses
         * at the end of a word and between phrases.
         * @private
         */
        _tickPlaceholder: function () {
            var self = this,
                state = this.typing,
                phrases = this.options.placeholders,
                text,
                wait;

            if (!state || this.$input.val()) {
                this._stopPlaceholderCycle();
                return;
            }

            text = phrases[state.phrase];

            if (state.deleting) {
                state.chars--;
                wait = this.options.deletingSpeed;

                if (state.chars <= 0) {
                    state.chars = 0;
                    state.deleting = false;
                    state.phrase = (state.phrase + 1) % phrases.length;
                    wait = this.options.delayAfterDeleted;
                }
            } else {
                state.chars++;
                wait = this.options.typingSpeed;

                if (state.chars >= text.length) {
                    state.chars = text.length;
                    state.deleting = true;
                    wait = this.options.delayAfterTyped;
                }
            }

            this.$input.attr('placeholder', text.substring(0, state.chars));
            this.typeTimer = setTimeout(function () {
                self._tickPlaceholder();
            }, wait);
        },

        /** @private */
        _stopPlaceholderCycle: function () {
            clearTimeout(this.typeTimer);
            this.typing = null;
            this.$input.attr('placeholder', this.staticPlaceholder);
        },

        /** @private */
        _destroy: function () {
            this._abort();
            this._stopPlaceholderCycle();
            this.$input.off('.suggest');
            this.$form.off('.suggest');
            $(document).off('click.suggest');
        }
    });

    return $.productify.searchSuggest;
});
