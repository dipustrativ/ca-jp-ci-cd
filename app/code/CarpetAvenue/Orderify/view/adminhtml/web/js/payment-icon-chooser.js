/**
 * Admin payment-icon chooser for the Icons FieldArray.
 *
 * - "Choose / Upload" button opens a modal listing existing icons in
 *   pub/media/payment_icons/ and lets the admin upload a new SVG/PNG.
 * - Selecting/uploading writes the relative media path into the row input and
 *   shows a preview thumbnail.
 * - Rows are drag-sortable; submit order = strip render order.
 */
define([
    'jquery',
    'Magento_Ui/js/modal/modal',
    'jquery/ui'
], function ($, modal) {
    'use strict';

    return function (config) {
        var current = null,
            $modal = null,
            modalInited = false,
            existingNames = {},
            cacheBust = 0;

        function bust(url) {
            return url + (url.indexOf('?') === -1 ? '?' : '&') + 'v=' + (cacheBust++);
        }

        function iconSrc($input) {
            var v = ($input.val() || '').trim();

            if (!v) {
                return '';
            }
            if (/^https?:\/\//i.test(v)) {
                return v;
            }
            return (config.mediaBaseUrl || '') + v.replace(/^\//, '');
        }

        function refreshPreview($input, force) {
            var $img = $input.closest('.payment-icon-field').find('.payment-icon-preview'),
                src = iconSrc($input);

            if (!src) {
                $img.removeAttr('src').removeData('base').hide();
                return;
            }
            // Skip if unchanged unless forced (force = file was replaced
            // server-side, same URL must bypass the browser image cache).
            if (!force && $img.data('base') === src) {
                return;
            }
            $img.data('base', src).attr('src', bust(src)).show();
        }

        function refreshAll(force) {
            $('.payment-icon-input').each(function () {
                refreshPreview($(this), force);
            });
        }

        function select(path) {
            if (current) {
                current.val(path).trigger('change');
                refreshPreview(current);
            }
            $modal.modal('closeModal');
        }

        function renderList(items) {
            var $grid = $modal.find('.payment-icon-grid').empty();

            existingNames = {};
            if (!items || !items.length) {
                $grid.append($('<p/>').text($.mage.__('No icons yet. Upload one above.')));
                return;
            }
            $.each(items, function (_, it) {
                existingNames[it.name.toLowerCase()] = true;
                $('<div class="payment-icon-grid__item"/>')
                    .append($('<img/>').attr({ src: bust(it.url), alt: it.name, title: it.name }))
                    .append($('<span/>').text(it.name))
                    .on('click', function () {
                        select(it.path);
                    })
                    .appendTo($grid);
            });
        }

        function loadList() {
            var $grid = $modal.find('.payment-icon-grid');

            // form_key passed explicitly: the backend ajaxSetup forces POST and
            // its beforeSend form_key injection is lost for dataless requests
            // (body becomes "[object Object]") -> "Invalid Form Key".
            $.ajax({
                url: config.listingUrl,
                type: 'POST',
                data: { form_key: config.formKey },
                dataType: 'json'
            }).done(function (r) {
                if (!r || r.success !== true) {
                    $grid.empty().append(
                        $('<p class="payment-icon-grid__error"/>').text(
                            $.mage.__('Could not load icons: ') + ((r && r.message) || $.mage.__('unknown error'))
                        )
                    );
                    return;
                }
                renderList(r && r.items);
            }).fail(function (xhr) {
                // A 403/HTML response usually means a stale admin session
                // (ACL cached in session) — re-login fixes it.
                $grid.empty().append(
                    $('<p class="payment-icon-grid__error"/>').text(
                        $.mage.__('Could not load icons (HTTP %1). Try logging out and back in.').replace('%1', xhr.status)
                    )
                );
            });
        }

        function uploadFiles(files) {
            var queue = Array.prototype.slice.call(files),
                total = queue.length,
                failed = [],
                replaced = [],
                lastPath = null,
                $status = $modal.find('.payment-icon-upload__status');

            function finish() {
                var parts = [];

                loadList();
                if (replaced.length) {
                    // Same URL, new file — force previews past the image cache.
                    refreshAll(true);
                    parts.push($.mage.__('Replaced: ') + replaced.join(', '));
                }
                if (failed.length) {
                    parts.push($.mage.__('Failed: ') + failed.join(', '));
                }
                $status.text(parts.join(' — '));
                if (!failed.length && total === 1 && lastPath) {
                    select(lastPath);
                }
            }

            function next() {
                var file, fd;

                if (!queue.length) {
                    finish();
                    return;
                }
                file = queue.shift();

                if (existingNames[file.name.toLowerCase()] &&
                    !window.confirm(
                        $.mage.__('"%1" already exists. Replace it?').replace('%1', file.name)
                    )
                ) {
                    next();
                    return;
                }
                fd = new FormData();
                $status.text($.mage.__('Uploading %1/%2...')
                    .replace('%1', total - queue.length).replace('%2', total));
                fd.append('icon', file);
                fd.append('form_key', config.formKey);

                $.ajax({
                    url: config.uploadUrl,
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json'
                }).done(function (r) {
                    if (r && r.success) {
                        lastPath = r.path;
                        if (r.replaced) {
                            replaced.push(r.name);
                        }
                    } else {
                        failed.push(file.name + ((r && r.message) ? ' (' + r.message + ')' : ''));
                    }
                }).fail(function () {
                    failed.push(file.name);
                }).always(next);
            }

            next();
        }

        function buildModal() {
            var $c = $(
                '<div class="payment-icon-modal">' +
                    '<div class="payment-icon-upload">' +
                        '<label class="action-default payment-icon-upload__btn">' +
                            '<span>' + $.mage.__('Upload new (SVG/PNG) — multiple allowed') + '</span>' +
                            '<input type="file" multiple accept=".svg,.png,.jpg,.jpeg,.gif,.webp"/>' +
                        '</label>' +
                        '<span class="payment-icon-upload__status"></span>' +
                    '</div>' +
                    '<div class="payment-icon-grid"></div>' +
                '</div>'
            );

            $c.find('input[type=file]').on('change', function () {
                if (this.files && this.files.length) {
                    uploadFiles(this.files);
                    this.value = '';
                }
            });

            $modal = $c.appendTo('body');
            $modal.modal({
                type: 'slide',
                title: $.mage.__('Choose / Upload payment icon'),
                modalClass: 'payment-icon-modal-wrap',
                buttons: []
            });
            modalInited = true;
        }

        function openChooser($input) {
            current = $input;
            if (!modalInited) {
                buildModal();
            }
            loadList();
            $modal.modal('openModal');
        }

        function initSortable() {
            $('table.admin__control-table').each(function () {
                var $tb = $(this).find('tbody');

                if ($tb.find('.payment-icon-field').length && !$tb.data('paymentIconSortable')) {
                    $tb.data('paymentIconSortable', true).sortable({
                        items: 'tr',
                        axis: 'y',
                        tolerance: 'pointer',
                        cancel: 'input,button,a,select,textarea,label'
                    });
                }
            });
        }

        $(document)
            .on('click', '.payment-icon-choose', function (e) {
                e.preventDefault();
                openChooser($(this).closest('.payment-icon-field').find('.payment-icon-input'));
            })
            .on('change keyup', '.payment-icon-input', function () {
                refreshPreview($(this));
            });

        $(function () {
            refreshAll();
            initSortable();
            // FieldArray injects rows via an inline script; catch late inserts.
            var mo = new MutationObserver(function () {
                refreshAll();
                initSortable();
            });
            mo.observe(document.body, { childList: true, subtree: true });
        });
    };
});
