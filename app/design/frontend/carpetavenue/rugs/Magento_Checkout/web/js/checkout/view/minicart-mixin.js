/**
 *
 * @description     Mixin for checkout/view/minicart.
 * @author          Evalent AB (Linus Jönsson)
 *
 */

define([
    'uiComponent',
    'Magento_Customer/js/customer-data',
    'jquery',
    'ko',
    'underscore',
    'sidebar',
    'mage/translate'
], function (Component, customerData, $, ko, _) {
    'use strict';

    var miniCart;
    var showCart;

    miniCart = $('[data-block=\'minicart\']');
    showCart = $('.action.showcart');

    return function (Component) {
        return Component.extend({

            /**
             * @override
             */
            initialize: function() {
                this._super();

                let subtotalHtml = '<span class="subtotal-label">' +
                    '<span class="price-wrapper" data-bind="html: getCartParam(\'subtotal_incl_tax\')"></span>' +
                    '</span>';
                showCart.append(subtotalHtml);
            },

            /**
             * @override
             */
            update: function (updatedCart) {
                this._super();

                if (updatedCart['summary_count'] > 0) {
                    if (!miniCart.hasClass('has-items')) {
                        miniCart.addClass('has-items');
                        miniCart.removeClass('zero');
                    }

                    if(miniCart.hasClass('active')) {
                        $(miniCart).find('[data-role="dropdownDialog"]').dropdownDialog('close');
                        setTimeout(function() {
                            // Hack
                            $(miniCart).find('[data-role="dropdownDialog"]').dropdownDialog('open');
                            $(document).trigger('minicart:open');
                        },500);
                    }

                } else {
                    miniCart.removeClass('has-items');
                    miniCart.addClass('zero');
                }
            }
        });
    }
});
