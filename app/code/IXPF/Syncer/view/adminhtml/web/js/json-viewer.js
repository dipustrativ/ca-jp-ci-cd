/**
 * JSON Tree Viewer with collapsible functionality
 */
define([
    'jquery'
], function ($) {
    'use strict';

    return {
        /**
         * Initialize JSON viewer
         */
        init: function() {
            this.initializeJsonViewers();
        },

        /**
         * Initialize all JSON viewer containers
         */
        initializeJsonViewers: function() {
            var self = this;
            $('.json-viewer-container').each(function() {
                var $container = $(this);
                var jsonData = $container.data('json');

                if (jsonData) {
                    try {
                        var parsed = typeof jsonData === 'string' ? JSON.parse(jsonData) : jsonData;
                        var html = self.renderJson(parsed, 0);
                        $container.html(html);
                        self.attachEventHandlers($container);
                    } catch (e) {
                        console.error('JSON parse error:', e);
                    }
                }
            });
        },

        /**
         * Render JSON as collapsible tree
         */
        renderJson: function(data, depth) {
            var html = '';
            var indent = '  '.repeat(depth);

            if (data === null) {
                return '<span class="json-null">null</span>';
            }

            if (typeof data === 'boolean') {
                return '<span class="json-boolean">' + data + '</span>';
            }

            if (typeof data === 'number') {
                return '<span class="json-number">' + data + '</span>';
            }

            if (typeof data === 'string') {
                return '<span class="json-string">"' + this.escapeHtml(data) + '"</span>';
            }

            if (Array.isArray(data)) {
                if (data.length === 0) {
                    return '<span class="json-bracket">[]</span>';
                }

                html += '<span class="json-toggle json-toggle-expanded" data-depth="' + depth + '">▼</span>';
                html += '<span class="json-bracket">[</span>';
                html += '<div class="json-content json-content-expanded">';

                data.forEach(function(item, index) {
                    html += '<div class="json-item" style="margin-left: ' + ((depth + 1) * 20) + 'px;">';
                    html += this.renderJson(item, depth + 1);
                    if (index < data.length - 1) {
                        html += '<span class="json-comma">,</span>';
                    }
                    html += '</div>';
                }.bind(this));

                html += '</div>';
                html += '<div style="margin-left: ' + (depth * 20) + 'px;"><span class="json-bracket">]</span></div>';
                return html;
            }

            if (typeof data === 'object') {
                var keys = Object.keys(data);
                if (keys.length === 0) {
                    return '<span class="json-bracket">{}</span>';
                }

                html += '<span class="json-toggle json-toggle-expanded" data-depth="' + depth + '">▼</span>';
                html += '<span class="json-bracket">{</span>';
                html += '<div class="json-content json-content-expanded">';

                keys.forEach(function(key, index) {
                    html += '<div class="json-item" style="margin-left: ' + ((depth + 1) * 20) + 'px;">';
                    html += '<span class="json-key">"' + this.escapeHtml(key) + '"</span>';
                    html += '<span class="json-colon">: </span>';
                    html += this.renderJson(data[key], depth + 1);
                    if (index < keys.length - 1) {
                        html += '<span class="json-comma">,</span>';
                    }
                    html += '</div>';
                }.bind(this));

                html += '</div>';
                html += '<div style="margin-left: ' + (depth * 20) + 'px;"><span class="json-bracket">}</span></div>';
                return html;
            }

            return '';
        },

        /**
         * Attach click handlers for toggle functionality
         */
        attachEventHandlers: function($container) {
            $container.off('click', '.json-toggle').on('click', '.json-toggle', function(e) {
                e.stopPropagation();
                var $toggle = $(this);
                var $content = $toggle.siblings('.json-content');

                if ($toggle.hasClass('json-toggle-expanded')) {
                    $toggle.removeClass('json-toggle-expanded').addClass('json-toggle-collapsed');
                    $toggle.text('▶');
                    $content.removeClass('json-content-expanded').addClass('json-content-collapsed');
                } else {
                    $toggle.removeClass('json-toggle-collapsed').addClass('json-toggle-expanded');
                    $toggle.text('▼');
                    $content.removeClass('json-content-collapsed').addClass('json-content-expanded');
                }
            });
        },

        /**
         * Escape HTML entities
         */
        escapeHtml: function(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
    };
});

