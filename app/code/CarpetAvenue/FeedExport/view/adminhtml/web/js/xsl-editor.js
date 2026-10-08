/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * XSL Template editor (Ace) synced into the profile form data source, plus
 * "Test XSL Template" / "Show fields available for export" (Xtento-style).
 */
define([
    'jquery',
    'uiRegistry',
    'mage/translate'
], function ($, registry, $t) {
    'use strict';

    var defaults = {
        provider: 'carpetavenue_feedexport_profile_form.carpetavenue_feedexport_profile_form_data_source',
        dataScope: 'data.xsl_template'
    };

    return function (options, element) {
        var config = $.extend({}, defaults, options),
            $root = $(element),
            editor = null,
            provider = null;

        /**
         * @param {String} url
         * @returns {Promise}
         */
        function loadScript(url) {
            return new Promise(function (resolve, reject) {
                var script = document.createElement('script');

                script.src = url;
                script.onload = resolve;
                script.onerror = reject;
                document.head.appendChild(script);
            });
        }

        /**
         * The vendored Ace is a "noconflict" build: it keeps its own loader under window.ace
         * (ace.define / ace.require) and never touches Magento's RequireJS. Files load in order.
         *
         * @param {Function} callback
         * @param {Function} fallback
         */
        function loadAce(callback, fallback) {
            var done = false,
                timer = setTimeout(function () {
                    finish(fallback);
                }, 10000),
                ready = window.ace && typeof window.ace.edit === 'function' && window.ace.require ?
                    Promise.resolve() :
                    loadScript(config.aceBaseUrl + 'ace.min.js').then(function () {
                        return Promise.all([
                            loadScript(config.aceBaseUrl + 'mode-xml.min.js'),
                            loadScript(config.aceBaseUrl + 'theme-eclipse.min.js')
                        ]);
                    });

            /**
             * @param {Function} handler
             */
            function finish(handler) {
                if (done) {
                    return;
                }
                done = true;
                clearTimeout(timer);

                try {
                    handler();
                } catch (e) {
                    console.error('XSL editor failed, using plain textarea.', e);

                    if (handler !== fallback) {
                        fallback();
                    }
                }
            }

            ready.then(function () {
                finish(callback);
            }, function () {
                finish(fallback);
            });
        }

        /**
         * Without Ace, edit in a plain textarea bound to the same data.
         */
        function showTextarea() {
            var $wrapper = $root.find('[data-role=editor-wrapper]'),
                $textarea = $('<textarea class="feedexport-xsl-textarea admin__control-textarea"></textarea>');

            $textarea.val(provider.get(config.dataScope) || '').on('input change', function () {
                provider.set(config.dataScope, $textarea.val());
            });
            $wrapper.removeClass('feedexport-xsl-editor').empty().append($textarea);
        }

        /**
         * Mode and theme files are already loaded, so Ace resolves them without fetching.
         */
        function initEditor() {
            var ace = window.ace,
                $editor = $root.find('[data-role=editor]');

            ace.config.set('basePath', config.aceBaseUrl);
            ace.config.set('suffix', '.min.js');
            editor = ace.edit($editor[0]);
            editor.$blockScrolling = Infinity;
            editor.setTheme('ace/theme/eclipse');
            editor.getSession().setUseWorker(false);
            editor.getSession().setMode('ace/mode/xml');
            editor.getSession().setUseWrapMode(true);
            editor.setBehavioursEnabled(false);
            editor.getSession().setValue(provider.get(config.dataScope) || '');
            editor.getSession().on('change', function () {
                provider.set(config.dataScope, editor.getSession().getValue());
            });

            // Created inside a hidden tab: Ace measures 0x0 until the tab is shown.
            if (window.ResizeObserver) {
                new window.ResizeObserver(function () {
                    editor.resize();
                }).observe($editor[0]);
            }
        }

        /**
         * @returns {String}
         */
        function getTestId() {
            return $.trim($root.find('[data-role=test-id]').val());
        }

        /**
         * @param {String} text
         */
        function showResults(text) {
            var $results = $root.find('[data-role=results]');

            $results.find('[data-role=results-content]').val(text);
            $results.show();
            $results[0].scrollIntoView({behavior: 'smooth', block: 'nearest'});
        }

        /**
         * Serve the stored test output through a hidden iframe (download, no navigation).
         */
        function serveToBrowser() {
            var frame = $root.find('iframe[data-role=download-frame]')[0];

            if (!frame) {
                frame = $('<iframe data-role="download-frame" style="display:none"></iframe>').appendTo($root)[0];
            }
            frame.src = config.downloadUrl;
        }

        function testTemplate() {
            var serve = $root.find('[data-role=serve-to-browser]').is(':checked');

            $.ajax({
                url: config.testUrl,
                type: 'POST',
                dataType: 'text',
                showLoader: true,
                data: {
                    'form_key': window.FORM_KEY,
                    'xsl_template': provider.get(config.dataScope) || '',
                    'test_id': getTestId(),
                    'profile_id': config.profileId,
                    'serve_to_browser': serve ? 'true' : 'false'
                }
            }).done(function (text) {
                showResults(text);

                if (serve && !/^Error: /.test(text)) {
                    serveToBrowser();
                }
            }).fail(function (xhr) {
                showResults($t('There was a problem testing the XSL Template. AJAX request failed.') +
                    '\n\n' + (xhr.responseText || ''));
            });
        }

        function showFields() {
            if (!config.profileId) {
                showResults($t('Error: Save the profile first, then show the fields.'));

                return;
            }
            window.open(
                config.fieldsUrl.replace('__TEST_ID__', encodeURIComponent(getTestId() || '0')),
                '_blank',
                'width=1000,height=700,toolbar=0,location=1,menubar=0,scrollbars=1'
            );
        }

        $root.on('click', '[data-role=test-template]', testTemplate);
        $root.on('click', '[data-role=show-fields]', function (event) {
            event.preventDefault();
            showFields();
        });

        registry.get(config.provider, function (dataProvider) {
            provider = dataProvider;
            loadAce(initEditor, showTextarea);
        });
    };
});
