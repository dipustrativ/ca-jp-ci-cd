define([
    'jquery'
], function ($) {
    'use strict';

    return function (validator) {
        validator.addRule(
            'validate-region-required',
            function (value, params, additionalParams) {
                // Skip if no additional params provided
                if (!additionalParams || !additionalParams.requiredCountries) {
                    return true;
                }

                const countryId = additionalParams.countryId || '';
                const requiredCountries = additionalParams.requiredCountries || [];

                // If current country is in required list, validate
                if (requiredCountries.indexOf(countryId) !== -1) {
                    return $.trim(value).length > 0;
                }

                return true; // Optional for other countries
            },
            $.mage.__('Please select a region, state or province.')
        );

        return validator;
    };
});