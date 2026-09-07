/**
 * Webkul contactus js.
 * @category Webkul
 * @package Webkul_AjaxContactForm
 * @author Webkul
 * @copyright Copyright (c) Webkul Software Private Limited (https://webkul.com)
 * @license https://store.webkul.com/license.html
 */
/*jshint jquery:true*/
define([
    'jquery',
    'mage/translate',
    'Magento_Ui/js/modal/alert',
    'Magento_Ui/js/modal/modal',
    'jquery/ui',
    'Webkul_AjaxContactForm/js/intlTelInput'
], function ($, $t, alert, modal) {
    'use strict';
    $.widget('mage.ajaxcontactform', {
        options: {},
        popup: null,
        _create: function () {
            var self = this;
            var modalOptions = {
                type: 'popup',
                responsive: true,
                title: self.options.title,
                modalClass: "ajaxcontactform-popup",
                captchaId: "",
                buttons: [
                    {
                        text: $t('Close'),
                        class: 'button action secondary close',
                        click: function () {
                            this.closeModal();
                        }
                    },
                    {
                        text: $t('Send'),
                        class: 'button action primary send',
                        click: function () {
                            self.submitForm(self);
                        }
                    }
                ],
                closed: function () {
                    $('.modal-popup.ajaxcontactform-popup').find('.modal-footer').show();
                    $('.modal-popup.ajaxcontactform-popup').find('form').first().trigger("reset");
                },
                opened: function () {

                    $('.modal-popup.ajaxcontactform-popup').find('form').first().submit(false)
                    let responseField = $('.modal-popup.ajaxcontactform-popup [name="g-recaptcha-response"]').first();
                    if (responseField.length > 0) {
                        responseField = responseField[0]
                        console.log(responseField);
                        self.captchaId = responseField.id.match(/\d+/) ? responseField.id.match(/\d+/)[0] : 0
                        console.log(self.captchaId);
                        window.grecaptcha.execute(self.captchaId)
                        console.log(window.grecaptcha.getResponse(self.captchaId))
                        console.log("Executed")
                    }

                    $(".ajaxcontactform-popup .telephone").intlTelInput({
                        separateDialCode: true,
                        autoHideDialCode: false,
                        nationalMode: false,
                        preferredCountries: self.options.preferredCountries
                    });
                }
            };
            $(document).ready(function () {

                var triggerid = self.options.triggerid,
                    panelid = self.options.panelid;

                self.popup = modal(modalOptions, $("."+panelid));
                $("."+triggerid).click(function() {
                    $("."+panelid).modal('openModal');
                });

                if(self.options.alignment === "3") {
                    $("#contact-megamenu-trigger").append($("."+triggerid));
                    $("."+triggerid).css('display','inline-block');
                }
            });
        },
        submitForm: function (self) {
            $(".error").hide();
            var hasError = false;
            var emailto  = self.options.emailto;
            var fromStore = self.options.fromstore;
            var usernameVal = $("#Username").val();
            var messageVal = $("#Usermessage").val();
            var recapture_token = $("#google-recaptcha-token").val();
            var emailReg = /^([\w-\.]+@([\w-]+\.)+[\w-]{2,4})?$/;

            var teleVal = $("#UserPhone").val();
            var dialCodeVal = $('.ajaxcontactform-popup .iti__selected-dial-code').text();
            if (teleVal == '') {
                $("#UserPhone").after('<span class="error">'+$t("This is a required field.")+'</span>');
                hasError = true;
            }
            var emailaddressVal = $("#UserEmail").val();
            if (emailaddressVal == '') {
                $("#UserEmail").after('<span class="error">'+$t("This is a required field.")+'</span>');
                hasError = true;
            } else if (!emailReg.test(emailaddressVal)) {
                $("#UserEmail").after('<span class="error">'+$t("This is a required field.")+'</span>');
                hasError = true;
            }

            if (window.grecaptcha && window.grecaptcha.render) {
                if (window.grecaptcha.getResponse(self.captchaId) == "") {
                    $(".ajaxcontactform-popup .field-recaptcha").after('<span class="error">' + $t("This is a required field.") + '</span>');
                    hasError = true;
                }
            }


            if (hasError == true) {
                return false;
            } else {
                $('#wk-loading-mask-ajax').removeClass('wk-display-none-ajax');
                jQuery.ajax({
                    url: self.options.mail_url,
                    data : {
                        'emailto' : emailto,
                        'name' : usernameVal,
                        'email': emailaddressVal,
                        'phone': dialCodeVal + teleVal,
                        'message': messageVal,
                        'fromstore': fromStore,
                        'g-recaptcha-response': recapture_token,
                        'area': "contact_popup"
                    },
                    type: 'POST',
                    success:function (status) {
                        if (status['success']) {
                            $('.modal-popup.ajaxcontactform-popup').find('.modal-footer').hide();
                            $('.contact-form').hide();
                            $('#successdata').css({display:'block'})
                                .append("<p><strong>"+dialCodeVal + teleVal+"</strong></p><p><strong>"+emailaddressVal+"</strong></p>");

                            $('#wk-loading-mask-ajax').addClass('wk-display-none-ajax');
                        } else if (status['error']!== 'undefined') {
                            $('#err_data').append("<strong>"+status['error']+"</strong>").removeClass('wk-display-none-ajax');
                            $('#wk-loading-mask-ajax').addClass('wk-display-none-ajax');
                        }
                    },
                    error:function (xhr, status, error) {
                        $('#err_data span').append(error).css({display:'block'});
                        $('#wk-loading-mask-ajax').addClass('wk-display-none-ajax');
                    }
                });
            }
            return false;

        }
    });
    return $.mage.ajaxcontactform;
});
