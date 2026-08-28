/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Hides decimal zeros on even prices in JS-rendered prices (swatches,
 * configurable price box, minicart). Ported from Evalent_PriceFormat.
 * Replaces price-utils formatters only when window.hideZerosFromPrice is set
 * by the js-config template (config: productify/catalog_price/hide_zeros).
 */
define([
    'underscore'
], function (_) {
    'use strict';

    var globalPriceFormat = {
        requiredPrecision: 2,
        integerRequired: 1,
        decimalSymbol: ',',
        groupSymbol: ',',
        groupLength: ','
    };

    /**
     * Repeats {string} {times} times
     * @param  {String} string
     * @param  {Number} times
     * @return {String}
     */
    function stringPad(string, times) {
        return (new Array(times + 1)).join(string);
    }

    var mixin = {

        /**
         * Vendor formatPriceLocale with one change: precision drops to 0 when
         * the amount is a whole number.
         */
        formatPriceLocale: function (amount, format, isShowSign) {
            var s = '',
                precision, pattern, locale, r;

            format = _.extend(globalPriceFormat, format);
            precision = isNaN(format.requiredPrecision = Math.abs(format.requiredPrecision)) ?
                2 : format.requiredPrecision;

            // remove zeros
            if (Math.abs(Math.round(amount) - amount) < 0.01) {
                precision = 0;
            }

            pattern = format.pattern || '%s';
            locale = window.LOCALE || 'en-US';

            if (isShowSign === undefined || isShowSign === true) {
                s = amount < 0 ? '-' : isShowSign ? '+' : '';
            } else if (isShowSign === false) {
                s = '';
            }
            pattern = pattern.indexOf('{sign}') < 0 ? s + pattern : pattern.replace('{sign}', s);
            amount = Number(Math.round(Math.abs(+amount || 0) + 'e+' + precision) + ('e-' + precision));
            r = amount.toLocaleString(locale, {minimumFractionDigits: precision});

            return pattern.replace('%s', r).replace(/^\s\s*/, '').replace(/\s\s*$/, '');
        },

        /**
         * Vendor formatPrice with the same whole-number precision change.
         */
        formatPrice: function (amount, format, isShowSign) {
            var s = '',
                precision, integerRequired, decimalSymbol, groupSymbol, groupLength, pattern, i, pad, j, re, r, am;

            format = _.extend(globalPriceFormat, format);

            precision = isNaN(format.requiredPrecision = Math.abs(format.requiredPrecision)) ?
                2 : format.requiredPrecision;
            integerRequired = isNaN(format.integerRequired = Math.abs(format.integerRequired)) ?
                1 : format.integerRequired;
            decimalSymbol = format.decimalSymbol === undefined ? ',' : format.decimalSymbol;
            groupSymbol = format.groupSymbol === undefined ? '.' : format.groupSymbol;
            groupLength = format.groupLength === undefined ? 3 : format.groupLength;
            pattern = format.pattern || '%s';

            // remove zeros
            if (Math.abs(Math.round(amount) - amount) < 0.01) {
                precision = 0;
            }

            if (isShowSign === undefined || isShowSign === true) {
                s = amount < 0 ? '-' : isShowSign ? '+' : '';
            } else if (isShowSign === false) {
                s = '';
            }
            pattern = pattern.indexOf('{sign}') < 0 ? s + pattern : pattern.replace('{sign}', s);

            i = parseInt(
                amount = Number(Math.round(Math.abs(+amount || 0) + 'e+' + precision) + ('e-' + precision)),
                10
            ) + '';
            pad = i.length < integerRequired ? integerRequired - i.length : 0;

            i = stringPad('0', pad) + i;

            j = i.length > groupLength ? i.length % groupLength : 0;
            re = new RegExp('(\\d{' + groupLength + '})(?=\\d)', 'g');

            am = Number(Math.round(Math.abs(amount - i) + 'e+' + precision) + ('e-' + precision));
            r = (j ? i.substr(0, j) + groupSymbol : '') +
                i.substr(j).replace(re, '$1' + groupSymbol) +
                (precision ? decimalSymbol + am.toFixed(precision).replace(/-/, 0).slice(2) : '');

            return pattern.replace('%s', r).replace(/^\s\s*/, '').replace(/\s\s*$/, '');
        }
    };

    return function (priceUtils) {
        // only replace if hide zeros is enabled
        if (window.hideZerosFromPrice === true) {
            priceUtils.formatPriceLocale = mixin.formatPriceLocale;
            priceUtils.formatPrice = mixin.formatPrice;
        }

        return priceUtils;
    };
});
