/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Productify
 * @author    Strativ Magento Team
 *
 * Merged from CarpetAvenue_LayeredNavigation (carpetavenueFilter*) and
 * Lof_AjaxScroll (ajaxscroll). Keep the map keys byte-identical — templates and
 * third-party JS resolve them by name.
 */
var config = {
    map: {
        '*': {
            owlcarousel: 'CarpetAvenue_Productify/js/owl.carousel.min',

            // merged CarpetAvenue_LayeredNavigation
            carpetavenueFilterAjax: 'CarpetAvenue_Productify/js/filter-ajax',
            carpetavenuePriceSlider: 'CarpetAvenue_Productify/js/price-slider',

            // header + search-results-page suggestion dropdown
            productifySearchSuggest: 'CarpetAvenue_Productify/js/search-suggest',

            // merged Lof_AjaxScroll
            ajaxscroll: 'CarpetAvenue_Productify/js/ajaxscroll/script'
        }
    },
    shim: {
        'owlcarousel': {
            deps: ['jquery']
        },
        // merged Lof_AjaxScroll
        'CarpetAvenue_Productify/js/ajaxscroll/script': {
            deps: ['jquery']
        },
        'ajaxscroll': {
            deps: ['jquery']
        }
    },
    config: {
        mixins: {
            'Magento_Catalog/js/price-utils': {
                'CarpetAvenue_Productify/js/price-utils-mixin': true
            }
        }
    }
};
