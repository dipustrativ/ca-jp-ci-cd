/**
 *
 * @description     Mixin for Mageplaza_Osc.
 * @author          Evalent AB (Martin Rydin)
 *
 */

define(
    [
        'underscore',
        'jquery',
        'Magento_Checkout/js/view/summary/item/details'
    ],
    function (_, $) {
        'use strict';

        var products = window.checkoutConfig.quoteItemData;

        return function (Component) {
            return Component.extend({
                /**
                 * @extend
                 */
                isRound: function (parent) {
                    var itemWithAttributes = this.productWithAttributes(parent);

                    if (itemWithAttributes && itemWithAttributes.hasOwnProperty('product') &&
                        itemWithAttributes.product.hasOwnProperty('shape')) {
                        return itemWithAttributes.product.shape == 20;
                    }
                    return false;
                },

                    /**
                 * @extend
                 */
                hasOptions: function (parent) {
                    var itemWithAttributes = this.productWithAttributes(parent);
                    if (itemWithAttributes && itemWithAttributes.hasOwnProperty('options') && itemWithAttributes.options.length > 0) {
                        return true;
                    }
                    return false;
                },

                /**
                 * @extend
                 */
                getWidth: function (parent) {
                    var itemWithAttributes = this.productWithAttributes(parent);

                    if (itemWithAttributes && itemWithAttributes.hasOwnProperty('product') &&
                        itemWithAttributes.product.hasOwnProperty('width')) {
                        return itemWithAttributes.product.width == null ? false : itemWithAttributes.product.width;
                    }
                    return false;
                },

                /**
                 * @extend
                 */
                getLength: function (parent) {
                    var itemWithAttributes = this.productWithAttributes(parent);

                    if (itemWithAttributes && itemWithAttributes.hasOwnProperty('product') &&
                        itemWithAttributes.product.hasOwnProperty('length')) {
                        return itemWithAttributes.product.length == null ? false : itemWithAttributes.product.length;
                    }
                    return false;
                },

                /**
                 * @extend
                 */
                unique: function (parent) {
                    var itemWithAttributes = this.productWithAttributes(parent);

                    if (itemWithAttributes.hasOwnProperty('is_unique')) {
                        return itemWithAttributes.is_unique;
                    }
                    return false;
                },

                /**
                 * @extend
                 */
                productWithAttributes: function(parent) {
                    var itemWithAttributes = _.find(products, function (product) {
                        return product.item_id == parent.item_id;
                    });

                    return itemWithAttributes;
                }
            }
            );
    }
});
