/**
 * "Update Payment Icons from Sheet" button.
 *
 * Confirms, then POSTs to the update controller which rebuilds every listed
 * store view's icon strip from the configured Google Sheet. Shows the result
 * inline next to the button.
 */
define([
    'jquery',
    'mage/translate',
    'Magento_Ui/js/modal/confirm'
], function ($, $t, confirm) {
    'use strict';

    return function (config) {
        var $button = $('#orderify_payment_icons_update'),
            $result = $('#orderify_payment_icons_update_result');

        if (!$button.length) {
            return;
        }

        function setMessage(text, isError) {
            $result.text(text).css('color', isError ? '#e22626' : '#1a7f37');
        }

        $button.on('click', function (e) {
            e.preventDefault();

            confirm({
                title: $t('Update Payment Icons'),
                content: $t('This rebuilds the payment-icon strip for every store view listed in the Google Sheet, overwriting their current icons. Continue?'),
                actions: {
                    confirm: function () {
                        $button.prop('disabled', true);
                        setMessage($t('Updating…'), false);

                        $.ajax({
                            url: config.updateUrl,
                            type: 'POST',
                            dataType: 'json',
                            data: {form_key: config.formKey},
                            showLoader: true
                        }).done(function (response) {
                            if (response && response.success) {
                                // Treat partial failures as an error-coloured message.
                                setMessage(response.message || $t('Done.'), !!response.has_failures);
                            } else {
                                setMessage(
                                    (response && response.message) || $t('Update failed.'),
                                    true
                                );
                            }
                        }).fail(function () {
                            setMessage($t('Request failed. Check connection and try again.'), true);
                        }).always(function () {
                            $button.prop('disabled', false);
                        });
                    }
                }
            });
        });
    };
});
