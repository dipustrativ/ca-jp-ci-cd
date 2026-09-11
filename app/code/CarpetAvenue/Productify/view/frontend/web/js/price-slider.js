/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * Price range slider: jQuery UI slider + numeric inputs. On release (or after
 * typing pauses) it builds the ?price=from-to URL and hands it to the AJAX
 * filter widget when present, otherwise navigates.
 */
define([
    'jquery',
    'jquery-ui-modules/slider',
    'jquery-ui-modules/widget'
], function ($) {
    'use strict';

    $.widget('carpetavenue.priceSlider', {
        options: {
            min: 0,
            max: 0,
            from: 0,
            to: 0,
            step: 1,
            requestVar: 'price',
            inputDelay: 800
        },

        _create: function () {
            var self = this;

            this._$slider = this.element.find('[data-role=slider]');
            this._$from = this.element.find('[data-role=from]');
            this._$to = this.element.find('[data-role=to]');
            this._$ticks = this.element.find('[data-role=ticks]');
            this._inputTimer = null;

            this._renderTicks();

            this._$slider.slider({
                range: true,
                min: this.options.min,
                max: this.options.max,
                step: this.options.step,
                values: [this.options.from, this.options.to],
                slide: function (event, ui) {
                    self._$from.val(ui.values[0]);
                    self._$to.val(ui.values[1]);
                },
                stop: function (event, ui) {
                    self._apply(ui.values[0], ui.values[1]);
                }
            });

            this._bindTouch();

            this._$from.val(this.options.from);
            this._$to.val(this.options.to);

            this._on({
                'change [data-role=from]': '_onInput',
                'change [data-role=to]': '_onInput'
            });
        },

        /**
         * jQuery UI's mouse widget listens for mousedown/mousemove/mouseup only.
         * Touch browsers synthesise those for a tap but never for a drag, so the
         * handles are immovable on a phone — worse inside the filter drawer,
         * where the browser takes the gesture as a scroll instead. Re-dispatch
         * the touch stream as the mouse events jQuery UI is waiting for.
         *
         * Bound to the handles, not the track, so the drawer still scrolls
         * everywhere else. Pairs with `touch-action: none` on the handle.
         * @private
         */
        _bindTouch: function () {
            var map = {
                touchstart: 'mousedown',
                touchmove: 'mousemove',
                touchend: 'mouseup',
                touchcancel: 'mouseup'
            };

            this._$slider.on(
                'touchstart.priceSlider touchmove.priceSlider touchend.priceSlider touchcancel.priceSlider',
                '.ui-slider-handle',
                function (event) {
                    var touch = event.originalEvent.changedTouches[0];

                    // Keep the page from scrolling / zooming under the drag.
                    event.preventDefault();

                    // jQuery UI moves its move/up listeners to the document once
                    // the drag starts, so dispatching on the handle is enough —
                    // the events bubble up to them.
                    touch.target.dispatchEvent(new MouseEvent(map[event.type], {
                        bubbles: true,
                        cancelable: true,
                        view: window,
                        clientX: touch.clientX,
                        clientY: touch.clientY,
                        screenX: touch.screenX,
                        screenY: touch.screenY,
                        buttons: 1
                    }));
                }
            );
        },

        /**
         * Five evenly-spaced value labels (min → max quartiles) under the track,
         * matching the carpient.com reference.
         */
        _renderTicks: function () {
            var min = this.options.min,
                max = this.options.max,
                steps = 4,
                i,
                value,
                html = '';

            if (!this._$ticks.length || max <= min) {
                return;
            }

            for (i = 0; i <= steps; i++) {
                value = Math.round(min + (max - min) * (i / steps));
                html += '<span class="am-price-tick">' + value + '</span>';
            }
            this._$ticks.html(html);
        },

        _onInput: function () {
            var self = this;

            clearTimeout(this._inputTimer);
            this._inputTimer = setTimeout(function () {
                var from = parseFloat(self._$from.val()),
                    to = parseFloat(self._$to.val());

                if (isNaN(from)) { from = self.options.min; }
                if (isNaN(to)) { to = self.options.max; }
                from = Math.max(self.options.min, Math.min(from, self.options.max));
                to = Math.max(from, Math.min(to, self.options.max));

                self._$slider.slider('values', [from, to]);
                self._apply(from, to);
            }, this.options.inputDelay);
        },

        /**
         * Public: jump both handles to a range and apply it. Used by the
         * quick-pick preset buttons, which are plain markup outside this
         * widget — they should behave exactly as dragging the handles does,
         * so they route through the same _apply() and therefore the same
         * staging and live count.
         */
        setRange: function (from, to) {
            from = Math.max(this.options.min, Math.min(this.options.max, Number(from)));
            to = Math.max(this.options.min, Math.min(this.options.max, Number(to)));

            if (from > to) {
                return;
            }

            this._$slider.slider('values', [from, to]);
            this._$from.val(from);
            this._$to.val(to);
            this._apply(from, to);
        },

        _apply: function (from, to) {
            var url = new URL(window.location.href),
                ajaxWidget = $('body').data('carpetavenueFilterAjax');

            // Batch mode: only stage the range + refresh the live count; the
            // shopper applies everything at once via the "Show" button.
            if (ajaxWidget && ajaxWidget.options && ajaxWidget.options.batchApply && ajaxWidget.stagePrice) {
                ajaxWidget.stagePrice(from, to, this.options.requestVar, this.options.min, this.options.max);

                return;
            }

            if (from <= this.options.min && to >= this.options.max) {
                url.searchParams.delete(this.options.requestVar);
            } else {
                url.searchParams.set(this.options.requestVar, from + '-' + to);
            }
            url.searchParams.delete('p');

            if (ajaxWidget) {
                ajaxWidget._applyUrl(url.toString(), true);
            } else {
                window.location.assign(url.toString());
            }
        }
    });

    return $.carpetavenue.priceSlider;
});
