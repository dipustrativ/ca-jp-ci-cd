/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Category mapping tree: one taxonomy autocomplete per category, stored as JSON
 * {"category_id": "taxonomy path"} in the profile form data source (Xtento-style).
 */
define([
    'jquery',
    'uiRegistry',
    'jquery/ui-modules/widgets/autocomplete'
], function ($, registry) {
    'use strict';

    var defaults = {
        provider: 'carpetavenue_feedexport_profile_form.carpetavenue_feedexport_profile_form_data_source',
        mappingScope: 'data.category_mapping',
        taxonomyScope: 'data.taxonomy_source',
        defaultTaxonomy: 'google_ja_JP'
    };

    /**
     * @param {String} json
     * @returns {Object}
     */
    function parseMapping(json) {
        var mapping;

        try {
            mapping = JSON.parse(json || '{}');
        } catch (e) {
            mapping = {};
        }

        return $.isPlainObject(mapping) ? mapping : {};
    }

    return function (options, element) {
        var config = $.extend({}, defaults, options),
            $root = $(element),
            $source = $root.find('[data-role=taxonomy-source]');

        registry.get(config.provider, function (provider) {
            var mapping = parseMapping(provider.get(config.mappingScope)),
                taxonomy = provider.get(config.taxonomyScope);

            /**
             * @param {HTMLElement} input
             */
            function updateMapping(input) {
                var value = $.trim($(input).val()),
                    categoryId = String($(input).data('category-id'));

                if (value === '') {
                    delete mapping[categoryId];
                } else {
                    mapping[categoryId] = value;
                }
                provider.set(config.mappingScope, JSON.stringify(mapping));
            }

            /**
             * Autocomplete is created lazily on first focus; there can be hundreds of categories.
             *
             * @param {HTMLElement} input
             */
            function initAutocomplete(input) {
                if ($(input).data('uiAutocomplete')) {
                    return;
                }
                $(input).autocomplete({
                    minLength: 2,
                    delay: 250,
                    source: function (request, response) {
                        if (!$source.val()) {
                            response([]);

                            return;
                        }
                        $.getJSON(config.searchUrl, {
                            source: $source.val(),
                            term: request.term
                        }).done(function (items) {
                            response(Array.isArray(items) ? items : []);
                        }).fail(function () {
                            response([]);
                        });
                    },
                    select: function (event, ui) {
                        $(this).val(ui.item.label);
                        updateMapping(this);

                        return false;
                    }
                });
            }

            if (taxonomy === undefined || taxonomy === null) {
                taxonomy = config.defaultTaxonomy;
                provider.set(config.taxonomyScope, taxonomy);
            }
            $source.val(taxonomy);
            $source.on('change', function () {
                provider.set(config.taxonomyScope, $source.val());
            });

            $root.find('[data-category-id]').each(function () {
                $(this).val(mapping[String($(this).data('category-id'))] || '');
            });
            $root.on('focus', '[data-category-id]', function () {
                initAutocomplete(this);
            });
            $root.on('change', '[data-category-id]', function () {
                updateMapping(this);
            });
        });

        $root.on('click', '[data-role=toggle]', function (event) {
            event.preventDefault();
            $(this).closest('.feedexport-category-node').toggleClass('is-open');
        });
    };
});
