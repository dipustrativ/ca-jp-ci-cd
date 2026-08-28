define([
    'Magento_Ui/js/grid/columns/column'
], function (Column) {
    'use strict';

    return Column.extend({
        /**
         * Reads the pre-formatted size string set server-side by
         * CarpetAvenue\Productify\Ui\DataProvider\Product\Listing\Collector\Size.
         *
         * @param {Object} row
         * @returns {String}
         */
        getSize: function (row) {
            return (row.extension_attributes && row.extension_attributes.size) || '';
        }
    });
});
