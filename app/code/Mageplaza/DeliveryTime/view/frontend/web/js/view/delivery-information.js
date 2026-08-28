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
 * @package     Mageplaza_DeliveryTime
 * @copyright   Copyright (c) Mageplaza (https://www.mageplaza.com/)
 * @license     https://www.mageplaza.com/LICENSE.txt
 */

define(
    [
        'jquery',
        'ko',
        'underscore',
        'uiComponent',
        'Mageplaza_DeliveryTime/js/model/mpdt-data',
        'Mageplaza_DeliveryTime/js/model/delivery-information',
        'jquery/ui',
        'jquery/jquery-ui-timepicker-addon'
    ],
    function ($, ko, _, Component, mpDtData, deliveryInformation) {
        'use strict';

        var cacheKeyDeliveryDate = 'deliveryDate',
            cacheKeyDeliveryTime = 'deliveryTime',
            cacheKeyHouseSecurityCode = 'houseSecurityCode',
            cacheKeyDeliveryComment = 'deliveryComment',
            dateFormat = window.checkoutConfig.mpDtConfig.deliveryDateFormat,
            daysOff = window.checkoutConfig.mpDtConfig.deliveryDaysOff || [],
            dateOff = [],
            cutoffTime = window.checkoutConfig.mpDtConfig.cutoffTime;

        function prepareSubscribeValue(object, cacheKey) {
            var deliveryDateOff = _.pluck(window.checkoutConfig.mpDtConfig.deliveryDateOff, 'date_off'),
                deliveryData = mpDtData.getData(cacheKey);

            if (deliveryData && cacheKey === cacheKeyDeliveryDate) {
                _.each(deliveryDateOff, function (dateoff) {
                    if (dateToString(deliveryData) === dateToString(dateoff)) {
                        mpDtData.setData(cacheKey, '');
                    }
                });

                if (daysOff.indexOf(dateToString(deliveryData, true)) !== -1) {
                    mpDtData.setData(cacheKey, '');
                }
            }

            object(mpDtData.getData(cacheKey));
            object.subscribe(function (newValue) {
                mpDtData.setData(cacheKey, newValue);
            });
        }

        function dateToString(dt, isDay = false) {
            var date = new Date(dt),
                month = date.getMonth() + 1;

            if (!month) {
                dt = dt.split(/[\./-]+/);
                date = new Date(dt[2], dt[1], dt[0]);
                month = date.getMonth();
            }

            if (isDay) {
                return date.getDay();
            }

            return date.getDate() + month + date.getFullYear();
        }

        function formatDeliveryTime(time) {
            var from = time['from'][0] + 'h' + time['from'][1],
                to = time['to'][0] + 'h' + time['to'][1];
            return from + ' - ' + to;
        }

        return Component.extend({
            defaults: {
                template: 'Mageplaza_DeliveryTime/container/delivery-information'
            },
            deliveryDate: deliveryInformation().deliveryDate,
            deliveryTime: deliveryInformation().deliveryTime,
            houseSecurityCode: deliveryInformation().houseSecurityCode,
            deliveryComment: deliveryInformation().deliveryComment,
            deliveryTimeOptions: deliveryInformation().deliveryTimeOptions,
            isVisible: ko.observable(mpDtData.getData(cacheKeyDeliveryDate)),

            initialize: function () {
                this._super();

                var self = this;

                dateOff = _.pluck(window.checkoutConfig.mpDtConfig.deliveryDateOff, 'date_off');
                ko.bindingHandlers.mpdatepicker = {
                    init: function (element) {
                        var options = {
                            minDate: 0,
                            showButtonPanel: false,
                            dateFormat: dateFormat,
                            showOn: 'both',
                            buttonText: '',
                            beforeShowDay: function (date) {
                                return self.deliveryShowday(date)
                            },
                            beforeShow: function() {
                                $('#ui-datepicker-div').addClass('notranslate');
                            }
                        };
                        $(element).datepicker(options);
                    }
                };

                $.each(window.checkoutConfig.mpDtConfig.deliveryTime, function (index, item) {
                    self.deliveryTimeOptions.push(formatDeliveryTime(item));
                });

                prepareSubscribeValue(this.deliveryDate, cacheKeyDeliveryDate);
                prepareSubscribeValue(this.deliveryTime, cacheKeyDeliveryTime);
                prepareSubscribeValue(this.houseSecurityCode, cacheKeyHouseSecurityCode);
                prepareSubscribeValue(this.deliveryComment, cacheKeyDeliveryComment);

                this.isVisible = ko.computed(function () {
                    return !!self.deliveryDate();
                });

                return this;
            },

            removeDeliveryDate: function () {
                if (mpDtData.getData(cacheKeyDeliveryDate) && mpDtData.getData(cacheKeyDeliveryDate) != null) {
                    this.deliveryDate('');
                }
            },

            deliveryShowday: function (date) {
                var currentDay = date.getDay(),
                    currentDate = date.getDate(),
                    currentMonth = date.getMonth() + 1,
                    formatCurrentMonth = String(currentMonth).length > 1 ? currentMonth : '0' + currentMonth,
                    currentYear = date.getFullYear(),
                    dateToCheck = ('0' + currentDate).slice(-2) + '/' + formatCurrentMonth + '/' + currentYear,
                    isAvailableDay = daysOff.indexOf(currentDay) === -1,
                    isAvailableDate = $.inArray(dateToCheck, dateOff) === -1;
                if (cutoffTime == null) {
                    return [isAvailableDay && isAvailableDate];
                }else {
                    var now = new Date(),
                        timeCut = cutoffTime[0] * 3600000 + cutoffTime[1] * 60000 + cutoffTime[2] * 1000,
                        timeNow = now.getHours() * 3600000 + now.getMinutes() * 60000 + now.getSeconds() * 1000,
                        isToday = date.getDate() === now.getDate() && date.getMonth() === now.getMonth() && date.getFullYear() === now.getFullYear();
                    if (isToday){
                        if(timeNow < timeCut) {
                            return [isAvailableDay && isAvailableDate];
                        }else{
                            return false;
                        }
                    }else {
                        return [isAvailableDay && isAvailableDate];
                    }
                }
            }
        });
    }
);
