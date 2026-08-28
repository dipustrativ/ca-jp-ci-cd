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

define(
    [
        'jquery',
        'underscore',
        'Magento_Checkout/js/view/shipping',
        'Magento_Checkout/js/model/quote',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/action/set-shipping-information',
        'Mageplaza_Osc/js/action/payment-total-information',
        'Magento_Checkout/js/model/step-navigator',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/checkout-data',
        'Magento_Checkout/js/action/select-billing-address',
        'Magento_Checkout/js/action/select-shipping-address',
        'Magento_Checkout/js/model/address-converter',
        'Mageplaza_Osc/js/model/shipping-rate-service',
        'Magento_Checkout/js/model/shipping-service',
        'Mageplaza_Osc/js/model/checkout-data-resolver',
        'Mageplaza_Osc/js/model/address/auto-complete',
        'Mageplaza_Osc/js/model/compatible/amazon-pay',
        'Magento_Customer/js/model/address-list',
        'Mageplaza_Osc/js/action/reload-order-summary',
        'Mageplaza_Osc/js/action/reload-payment-method',
        'rjsResolver',
        'uiRegistry',
        'mage/translate'
    ],
    function ($,
              _,
              Component,
              quote,
              customer,
              setShippingInformationAction,
              getPaymentTotalInformation,
              stepNavigator,
              additionalValidators,
              checkoutData,
              selectBillingAddress,
              selectShippingAddress,
              addressConverter,
              shippingRateService,
              shippingService,
              oscDataResolver,
              addressAutoComplete,
              amazonPay,
              addressList,
              reloadOrderSummary,
              reloadPaymentMethod,
              resolver,
              registry) {
        'use strict';
        var oscConfig          = window.checkoutConfig.oscConfig.enableOscPro,
            loadingSpeedConfig = window.loadingSpeedConfig;
        oscDataResolver.resolveDefaultShippingMethod();

        /** Set shipping methods to collection */
        shippingService.setShippingRates(window.checkoutConfig.shippingMethods);

        return Component.extend({
            defaults: {
                template: 'Mageplaza_Osc/container/shipping',
                shippingMethodItemTemplate: 'Mageplaza_Osc/shipping-address/shipping-method-item'
            },
            currentMethod: null,
            isAmazonAccountLoggedIn: amazonPay.isAmazonAccountLoggedIn,
            initialize: function () {
                this._super();

                if (window.checkoutConfig.hasOwnProperty('amazonLogin')) {
                    this.isNewAddressAdded(this.isAmazonAccountLoggedIn());
                    this.isAmazonAccountLoggedIn.subscribe(function (value) {
                        this.isNewAddressAdded(value);
                    }, this);
                }

                /**
                 * Solve problem when customer has more than 1 addresses but no one is default shipping address
                 * Shipping address will not auto select the first one, so billing address throw error when trying to
                 * calculate isAddressSameAsShipping variable
                 */
                if (!quote.shippingAddress() && addressList().length >= 1) {
                    selectShippingAddress(addressList()[0]);
                }

                stepNavigator.steps.removeAll();

                //shippingRateService.estimateShippingMethod();
                additionalValidators.registerValidator(this);

                resolver(this.afterResolveDocument.bind(this));

                return this;
            },

            initObservable: function () {
                this._super();

                quote.shippingMethod.subscribe(function (oldValue) {
                    this.currentMethod = oldValue;
                }, this, 'beforeChange');

                quote.shippingMethod.subscribe(function (newValue) {
                    var isMethodChange = $.type(this.currentMethod) !== 'object'
                        ? true
                        : this.currentMethod.carrier_code + "_" + this.currentMethod.method_code;

                    if ($.type(newValue) === 'object' && isMethodChange !== newValue.carrier_code + "_" + newValue.method_code) {
                        if (!oscConfig || !loadingSpeedConfig || loadingSpeedConfig.refresh_page.includes('1')
                            || loadingSpeedConfig.shipping_method_change.includes('2')) {
                            setShippingInformationAction();
                        }else if (loadingSpeedConfig.shipping_method_change.includes('1')){
                            reloadPaymentMethod();
                        }

                    } else if (shippingRateService.isAddressChange) {
                        shippingRateService.isAddressChange = false;
                        getPaymentTotalInformation();
                    }
                }, this);

                return this;
            },

            afterResolveDocument: function () {
                addressAutoComplete.register('shipping');

                if(!quote.isVirtual() && quote.shippingAddress() && quote.shippingAddress().countryId){
                    shippingRateService.estimateShippingMethod();
                }

                var billingScope = registry.get("checkout.steps.shipping-step.billingAddress");

                this.isSelected.subscribe(function (value) {
                    if (value === 'instore_pickup') {
                        if (billingScope.isAddressSameAsShipping()){
                            $('#billing-address-same-as-shipping').trigger('click');
                        };
                        $('#shipping').hide();
                        $('#store-pickup').show();
                    } else {
                        $('#shipping').show();
                        $('#store-pickup').hide();
                    }

                });

                if (this.isSelected() === 'instore_pickup') {
                    billingScope.isAddressSameAsShipping(false);
                    $('#shipping').hide();
                    $('#store-pickup').show();
                }  else {
                    $('#shipping').show();
                    $('#store-pickup').hide();
                }
            },

            validate: function () {
                if (this.isAmazonAccountLoggedIn()) {
                    return true;
                }

                if (quote.isVirtual()) {
                    return true;
                }

                if (quote.shippingMethod() && quote.shippingMethod().carrier_code === 'instore') {
                    return true;
                }

                var shippingMethodValidationResult = true,
                    shippingAddressValidationResult = true,
                    loginFormSelector = 'form[data-role=email-with-possible-login]',
                    emailValidationResult = customer.isLoggedIn();

                if (!quote.shippingMethod()) {
                    this.errorValidationMessage($.mage.__('Please specify a shipping method.'));

                    shippingMethodValidationResult = false;
                }

                if (!customer.isLoggedIn()) {
                    $(loginFormSelector).validation();
                    emailValidationResult = Boolean($(loginFormSelector + ' input[name=username]').valid());
                }

                if (this.isFormInline) {
                    this.source.set('params.invalid', false);
                    this.source.trigger('shippingAddress.data.validate');

                    if (this.source.get('shippingAddress.custom_attributes')) {
                        this.source.trigger('shippingAddress.custom_attributes.data.validate');
                    }

                    if (this.source.get('params.invalid')) {
                        shippingAddressValidationResult = false;
                    }

                    this.saveShippingAddress();
                }

                return shippingMethodValidationResult && shippingAddressValidationResult && emailValidationResult;
            },
            saveShippingAddress: function () {
                var shippingAddress = quote.shippingAddress(),
                    addressData = addressConverter.formAddressDataToQuoteAddress(
                        this.source.get('shippingAddress')
                    );

                //Copy form data to quote shipping address object
                for (var field in addressData) {
                    if (addressData.hasOwnProperty(field) &&
                        shippingAddress.hasOwnProperty(field) &&
                        typeof addressData[field] != 'function' &&
                        _.isEqual(shippingAddress[field], addressData[field])
                    ) {
                        shippingAddress[field] = addressData[field];
                    } else if (typeof addressData[field] != 'function' && !_.isEqual(shippingAddress[field], addressData[field])) {
                        shippingAddress = addressData;
                        break;
                    }
                }

                if (customer.isLoggedIn()) {
                    shippingAddress.save_in_address_book = 1;
                }
                selectShippingAddress(shippingAddress);
            },

            saveNewAddress: function () {
                this._super();
                if (!this.source.get('params.invalid')) {
                    if (!loadingSpeedConfig || loadingSpeedConfig.refresh_page.includes('1') || !oscConfig) {
                        shippingRateService.isAddressChange = true;
                        shippingRateService.estimateShippingMethod();
                    }else {
                        if (loadingSpeedConfig.shipping_address_change.includes('1')){
                            shippingRateService.estimateShippingMethod();
                        } else if (loadingSpeedConfig.shipping_address_change.includes('2')){
                            reloadOrderSummary()
                        }

                    }
                }
            },

            /**
             * Add class to New Address popup modal
             * @return {*}
             */
            getPopUp: function () {
                if (this.popUpForm.element === '#opc-new-shipping-address') {
                    this.popUpForm.options.modalClass = 'osc-new-address-popup'
                }

                return this._super();
            },

            getAddressTemplate: function () {
                return 'Mageplaza_Osc/container/address/shipping-address';
            }
        });
    }
);
