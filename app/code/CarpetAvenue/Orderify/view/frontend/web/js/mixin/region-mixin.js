define([
    'jquery',
    'mage/utils/wrapper'
], function ($, wrapper) {
    'use strict';

    return function (Component) {
        return Component.extend({
            /**
             * Initialize component
             */
            initialize: function () {
                this._super();

                // Check if this field has optionalCountries configuration
                if (this.optionalCountries && Array.isArray(this.optionalCountries)) {
                    this.initOptionalRegion();
                }

                return this;
            },

            /**
             * Initialize optional region behavior
             */
            initOptionalRegion: function () {
                var self = this;

                // Subscribe to country changes
                if (this.parentComponent && this.parentComponent().country_id) {
                    this.parentComponent().country_id.subscribe(function (countryId) {
                        self.updateValidation(countryId);
                    });

                    // Initial check
                    var currentCountry = this.parentComponent().country_id();
                    if (currentCountry) {
                        this.updateValidation(currentCountry);
                    }
                }
            },

            /**
             * Update validation based on selected country
             */
            updateValidation: function (countryId) {
                if (!this.validation) {
                    this.validation = {};
                }

                // If country is in optional list, make field optional (remove required validation)
                if (this.optionalCountries.indexOf(countryId) !== -1) {
                    // Make it optional
                    delete this.validation['required-entry'];
                    this.required(false);
                } else {
                    // Use Magento default behavior (let Magento decide based on directory configuration)
                    // Don't change anything - let the default Magento validation apply
                    if (this.validation.hasOwnProperty('required-entry')) {
                        // Already has validation, keep it as is
                    }
                }
            }
        });
    };
});

