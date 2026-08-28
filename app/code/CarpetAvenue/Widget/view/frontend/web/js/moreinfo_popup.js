/*jshint jquery:true*/
define([
    'jquery',
    'mage/translate',
    'Magento_Ui/js/modal/modal',
    'jquery/ui'
    ], function ($, $t, modal) {
       'use strict';
       $.widget('carpetavenue.calloutpopup', {
           options: {},
           popup: null,
           _create: function () {
               var self = this;
               var modalOptions = {
                   type: 'popup',
                   responsive: true,
                   title: false,
                   modalClass: "callout-popup",
                   buttons: [
                       {
                           text: $t('Close'),
                           class: 'button action primary close',
                           click: function () {
                               this.closeModal();
                           }
                       }
                   ],
                   closed: function () {},
                   opened: function () {}
               };
               $(document).ready(function () {
                   self.popup = modal(modalOptions, $("#callout-widget--more-content"));
                   $(".callout-widget__showmore-link").click(function() {
                       $("#callout-widget--more-content").modal('openModal');
                   });
               });
           }
       });
       return $.carpetavenue.calloutpopup;
   });
