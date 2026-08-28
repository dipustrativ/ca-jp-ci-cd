/**
 * Carpient_Filter — per-attribute-option image modal.
 *
 * Wires the "Setting" button on each attribute-option row to a modal that
 * uploads / previews / deletes one image per option. Image is persisted by the
 * carpient_filter/optionImage/* admin controllers under media/evattribute/option/.
 */
define([
    'jquery',
    'Magento_Ui/js/modal/modal',
    'mage/translate',
    'mage/backend/notification'
], function ($) {
    'use strict';

    return function (config) {
        var $modalEl,
            currentOptionId = null;

        /**
         * Read the admin form key for POST requests.
         *
         * @returns {String}
         */
        function formKey() {
            return window.FORM_KEY || $('input[name="form_key"]').val() || '';
        }

        /**
         * A saved option has a numeric id; freshly-added rows use "option_N".
         *
         * @param {String} id
         * @returns {Boolean}
         */
        function isSaved(id) {
            return /^\d+$/.test(String(id));
        }

        /**
         * Lazily build the shared modal element.
         *
         * @returns {jQuery}
         */
        function buildModal() {
            if ($modalEl) {
                return $modalEl;
            }

            $modalEl = $(
                '<div class="carpient-option-image-modal">' +
                    '<div data-role="oi-preview" class="carpient-oi-preview"></div>' +
                    '<div class="carpient-oi-field">' +
                        '<input type="file" data-role="oi-file" accept="image/*" />' +
                    '</div>' +
                    '<div data-role="oi-message" class="carpient-oi-message"></div>' +
                '</div>'
            );

            $('body').append($modalEl);

            $modalEl.modal({
                type: 'popup',
                modalClass: 'carpient-option-image',
                title: $.mage.__('Option Image'),
                buttons: [
                    {
                        text: $.mage.__('Upload'),
                        class: 'action-primary',
                        click: uploadImage
                    },
                    {
                        text: $.mage.__('Delete Image'),
                        class: 'action-secondary',
                        click: deleteImage
                    },
                    {
                        text: $.mage.__('Close'),
                        class: 'action-dismiss',
                        click: function () {
                            this.closeModal();
                        }
                    }
                ]
            });

            return $modalEl;
        }

        /**
         * Show a transient message inside the modal.
         *
         * @param {String} text
         * @param {Boolean} isError
         */
        function setMessage(text, isError) {
            $modalEl.find('[data-role="oi-message"]')
                .css('color', isError ? '#e02b27' : '#1a7b34')
                .text(text || '');
        }

        /**
         * Render the current image preview (or an empty-state note).
         *
         * @param {String|null} url
         */
        function renderPreview(url) {
            var $preview = $modalEl.find('[data-role="oi-preview"]');

            if (url) {
                $preview.html($('<img>', {
                    src: url,
                    alt: '',
                    css: { maxWidth: '200px', maxHeight: '200px' }
                }));
            } else {
                $preview.html(
                    $('<span>').text($.mage.__('No image uploaded yet.'))
                );
            }
        }

        /**
         * Fetch and show the option's saved image.
         *
         * @param {String} optionId
         */
        function loadCurrent(optionId) {
            renderPreview(null);
            setMessage('');

            $.ajax({
                url: config.getUrl,
                method: 'GET',
                data: { option_id: optionId },
                dataType: 'json'
            }).done(function (res) {
                renderPreview(res && res.url ? res.url : null);
            });
        }

        /**
         * Upload the chosen file for the current option.
         */
        function uploadImage() {
            var fileInput = $modalEl.find('[data-role="oi-file"]')[0],
                data;

            if (!fileInput || !fileInput.files || !fileInput.files.length) {
                setMessage($.mage.__('Choose a file first.'), true);
                return;
            }

            data = new FormData();
            data.append('image', fileInput.files[0]);
            data.append('option_id', currentOptionId);
            data.append('form_key', formKey());

            $.ajax({
                url: config.uploadUrl,
                method: 'POST',
                data: data,
                dataType: 'json',
                processData: false,
                contentType: false,
                showLoader: true
            }).done(function (res) {
                if (res && res.errorcode) {
                    setMessage(res.error, true);
                    return;
                }
                fileInput.value = '';
                renderPreview(res.url);
                setMessage($.mage.__('Image uploaded.'), false);
            }).fail(function () {
                setMessage($.mage.__('Upload failed.'), true);
            });
        }

        /**
         * Delete the current option's image.
         */
        function deleteImage() {
            $.ajax({
                url: config.deleteUrl,
                method: 'POST',
                data: { option_id: currentOptionId, form_key: formKey() },
                dataType: 'json',
                showLoader: true
            }).done(function (res) {
                if (res && res.errorcode) {
                    setMessage(res.error, true);
                    return;
                }
                renderPreview(null);
                setMessage($.mage.__('Image deleted.'), false);
            }).fail(function () {
                setMessage($.mage.__('Delete failed.'), true);
            });
        }

        // Delegated so it also catches rows rendered after page load.
        $(document).on('click', '.option-image-setting', function () {
            var optionId = $(this).data('option-id');

            if (!isSaved(optionId)) {
                alert($.mage.__('Save the attribute first, then add an image for this option.'));
                return;
            }

            currentOptionId = optionId;
            buildModal();
            $modalEl.find('[data-role="oi-file"]').val('');
            loadCurrent(optionId);
            $modalEl.modal('openModal');
        });
    };
});
