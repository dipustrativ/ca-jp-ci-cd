/**
 * Mageplaza
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Mageplaza.com license that is
 * available through the world-wide-web at this URL:
 * https://www.mageplaza.com/LICENSE.txt
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Mageplaza
 * @package     Mageplaza_Osc
 * @copyright   Copyright (c) Mageplaza (https://www.mageplaza.com/)
 * @license     https://www.mageplaza.com/LICENSE.txt
 */

define(['jquery'], function ($) {
    'use strict';

    $.widget('mageplaza.osc_field_tabs', {
        _create: function () {
            this.initTabEvent();
            this.initSaveEvent();
        },

        initTabEvent: function () {
            var elem = $('#mposc-field-tabs .action-default');
            $("#position-save-messages").insertBefore('div#container');
            elem.on('click', function () {
                elem.removeClass('_active');
                $(this).addClass('_active');

                $('.mposc-field-container').hide();
                $(this.getAttribute('href')).show();

                if ($(this).parent().children('.action-default._active').index() > 0) {
                    $('#add_customer_attr').hide();
                    $('#add_order_attr').show();
                } else {
                    $('#add_customer_attr').show();
                    $('#add_order_attr').hide();
                }

                return false;
            });

            if (window.location.hash) {
                $('[href=' + window.location.hash + ']').trigger('click');
            } else {
                $(elem[0]).trigger('click');
            }
        },

        initSaveEvent: function () {
            var self = this;

            $('.mposc-save-position').on('click', function () {
                self.savePosition(self.options.url);
            });
            $('a.button.checkout-steps').on('click', function () {
                $.ajax({
                    method: 'post',
                    showLoader: true,
                    url: self.options.urlCheckoutSteps,
                    data: {
                        codeCheckoutSteps: $('.checkout-steps._active').attr('value')
                    },
                    success: function (response) {
                        $('#mposc-checkout-step').html(response.block_html);
                        $('#mposc-checkout-step .mposc-field-container').show();
                        self.initGrid()
                    }
                });
            });
        },
        initGrid: function () {
            var selector = '#mposc-checkout-step ',
                list     = $(selector + '.sortable-list'),
                field, elemWidth,
                options  = {
                    tolerance: 'pointer',
                    connectWith: '.sortable-list',
                    dropOnEmpty: true,
                    containment: 'body',
                    cancel: '.ui-state-disabled',
                    placeholder: 'suggest-position',
                    zIndex: 10,
                    scroll: false,
                    start: function (e, hash) {
                        if (hash.item.hasClass('wide')) {
                            hash.placeholder.addClass('wide');
                        }

                        if (hash.item.hasClass('medium')) {
                            hash.placeholder.addClass('medium');
                        }

                        if (hash.item.hasClass('short')) {
                            hash.placeholder.addClass('short');
                        }
                    }
                };

            list.sortable(options);

            $(selector + '.sortable-list li').disableSelection();
            $(selector + '.sortable-list li').addClass('f-left');

            $(selector + '.containment ul li .attribute-label').resizable({
                maxHeight: 40,
                minHeight: 40,
                zIndex: 10,
                cancel: '.ui-state-disabled',
                helper: 'ui-resizable-border',
                stop: function (e, ui) {
                    field     = ui.element.parent();
                    elemWidth = ui.element.width() / 2;

                    field.removeClass('wide');
                    field.removeClass('medium');
                    field.removeClass('short');

                    if (elemWidth < field.width() * 0.3) {
                        field.addClass('short');
                    } else if (elemWidth > field.width() * 0.6 && elemWidth < field.width() * 0.8) {
                        field.addClass('medium');
                    } else if (elemWidth > field.width() * 0.8) {
                        field.addClass('wide');
                    }

                    ui.element.css({width: ''});
                }
            });
        },

        savePosition: function (url) {
            var self     = this,
                fields   = [],
                oaFields = [],
                field    = {},
                parent   = null;

            $('#position-save-messages').html('');

            $('.sorted-wrapper .sortable-item').each(function (index, el) {
                parent = $(el).parents('.mposc-field-container');

                field = {
                    code: $(el).attr('data-code'),
                    colspan: self.getColspan($(el)),
                    required: !!$(el).find('.attribute-required input').is(':checked')
                };

                if ($(el).parents('#mposc-address-information').length) {
                    fields.push(field);
                } else if (!$(el).hasClass('ui-state-disabled')) {
                    field.bottom =
                        parent.find('#' + $(el).attr('id')).index() > parent.find('.ui-state-disabled').index();

                    oaFields.push(field);
                }
            });

            $.ajax({
                method: 'post',
                showLoader: true,
                url: url,
                data: {
                    fields: JSON.stringify(fields),
                    oaFields: JSON.stringify(oaFields)
                },
                success: function (response) {
                    $('#position-save-messages').html(
                        '<div class="message message-' + response.type + ' ' + response.type + ' ">' +
                        '<span>' + response.message + '</span>' +
                        '</div>'
                    );
                }
            });
        },

        getColspan: function (elem) {
            if (elem.hasClass('wide')) {
                return 12;
            } else if (elem.hasClass('medium')) {
                return 9;
            } else if (elem.hasClass('short')) {
                return 3;
            }

            return 6;
        }
    });

    return $.mageplaza.osc_field_tabs;
});
