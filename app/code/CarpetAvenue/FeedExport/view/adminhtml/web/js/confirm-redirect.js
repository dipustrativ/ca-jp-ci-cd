/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Button that asks for confirmation, then navigates to config.url.
 */
define([
    'jquery',
    'Magento_Ui/js/modal/confirm'
], function ($, confirm) {
    'use strict';

    return function (config, element) {
        $(element).on('click', function () {
            confirm({
                content: config.message,
                actions: {
                    /** Navigate on confirm. */
                    confirm: function () {
                        window.location.href = config.url;
                    }
                }
            });
        });
    };
});
