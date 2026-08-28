define([
    'jquery',
    'mage/utils/wrapper',
    'Magento_Ui/js/form/form',
    'Magento_Checkout/js/model/quote'
], function (
    $,
    wrapper,
    formComponent,
    quote
) {
    'use strict';
    return function (config, element) {
        // For shipping address
        quote.shippingAddress.subscribe(function (address) {
            if (!Array.isArray(config.optionalRegionCountries)) {
                return;
            }
            if (!address || !address.countryId) {
                return;
            }

            setTimeout( () => {
                var $country = $('div[name="shippingAddress.country_id"]')
                    .find('select[name="country_id"]');

                if ($country.val() !== address.countryId) {
                    $country
                        .val($country.val())
                        .trigger('change');
                }

                // Check for required region countries
                let requiredStateCountries =  typeof config.requiredRegionCountries === 'undefined' ? [] : config.requiredRegionCountries;
                if (requiredStateCountries.includes($country.val())) {
                    $('[name="shippingAddress.region_id"]').closest('.field').css('display', 'block');
                    return;
                }
                console.log('adfdfd')
                toggleShippingRegionFields($country.val());
            }, 1000);
        });

        // For billing address
        quote.billingAddress.subscribe(function (address) {
            if (!Array.isArray(config.optionalRegionCountries)) {
                return;
            }
            if (!address || !address.countryId) {
                return;
            }

            setTimeout( () => {
                var $country = $('div[name="billingAddress.country_id"]')
                    .find('select[name="country_id"]');

                if ($country.val() !== address.countryId) {
                    $country
                        .val($country.val())
                        .trigger('change');
                }

                // Check for required state countries
                let requiredStateCountries =  typeof config.requiredRegionCountries === 'undefined' ? [] : config.requiredRegionCountries;
                if (requiredStateCountries.includes($country.val())) {
                    $('[name="billingAddress.region_id"]').closest('.field').css('display', 'block');
                    return;
                }

                toggleBillingRegionFields($country.val());
            }, 1000);
        });

        function toggleShippingRegionFields(countryId) {
            if (config.optionalRegionCountries.includes(countryId)) {
                $('[name="shippingAddress.region_id"]').closest('.field').css('display', 'block');
            } else {
                $('[name="shippingAddress.region"]').closest('.field').css('display', 'none');
                $('[name="shippingAddress.region_id"]').closest('.field').css('display', 'none');
            }
        }

        function toggleBillingRegionFields(countryId) {
            if (config.optionalRegionCountries.includes(countryId)) {
                $('[name="billingAddress.region_id"]').closest('.field').css('display', 'block');
            } else {
                $('[name="billingAddress.region"]').closest('.field').css('display', 'none');
                $('[name="billingAddress.region_id"]').closest('.field').css('display', 'none');
            }
        }

    };
})
