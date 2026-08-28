define([
    'jquery',
    'mage/apply/main'
], function ($, mageApply) {
    'use strict';
    return function (config) {
        var productId = config.productId;
        var productName = config.productName || '';
        var width2 = config.width2 || '';
        var height2 = config.height2 || '';
        var productionType = config.productionType || '';
        var sortColor = config.sortColor || '';
        var listSize = config.listSize || 5;
        var title = config.similarProductTitle || 'You might also like';
        var showAllTax = config.showAllTax || 'Show All';
        var params = {
            similar: productId,
            q: productName,
            width2: width2,
            height2: height2,
            production_type: productionType,
            sort_color: sortColor,
        };

        var url = '/catalogsearch/result/?' + $.param(params);

        $.ajax({
            url: url,
            method: "GET",
            success: function (response) {
                var $response = $('<div>').html(response);

                var $productList = $response.find('.products.list.items.product-items');

                if ($productList.length) {
                    // var $items = $productList.find('li.product-item')
                    var $items = $productList.find('li.product-item').filter(function() {
                        return !$(this).find('.sold-caption').length;
                    });

                    $items = $items.filter(function() {
                        return !$(this).find('[data-bind]').length;
                    });

                    var $limitedItems = $items.slice(0, listSize);
                    if (!$limitedItems.length) {
                        $('body .catalog-product-similar-ajax').html('');
                        return;
                    }

                    var $block = $('<div class="block page-main widget similar"></div>');
                    var $blockTitle = $('<div class="block-title title"><strong id="block-page-main widget similar-heading" role="heading" aria-level="2">'+title+'</strong></div>');
                    var $blockContent = $('<div class="block-content content block elastyslider" aria-labelledby="block-page-main widget similar-heading"></div>');
                    var $wrapper = $('<div class="products wrapper grid products-grid products-similar elastyslider__slider"><div class="products list items product-items row elastyslider__slides"></div></div>');

                    $limitedItems.each(function() {
                        var $li = $(this);
                        var $div = $('<div class="item product ev-product-item col-md-3 col-lg-2 col-xs-6 elastyslider__slide"></div>');
                        $div.html($li.html());
                        $wrapper.find('.products.list.items.product-items').append($div);
                    });

                    $blockContent.append($wrapper);
                    $block.append($blockTitle).append($blockContent);

                    var $showAllBtn = $('<div class="widget_category-btn-holder"><a href="' + url + '" class="action primary select widget_category-show-all" role="button"><span>'+showAllTax+'</span></a></div>');
                    $block.append($showAllBtn);

                    $('body .catalog-product-similar-ajax').html($block);
                } else {
                    $('body .catalog-product-similar-ajax').html(response);
                }

                $("body .catalog-product-similar-ajax .lazy-load").lazyload({
                    effect : "fadeIn",
                    threshold: 1000
                });

                require(['jquery', 'js/scrollbar'], function($) {
                    $('body .catalog-product-similar-ajax .elastyslider__slides').scrollbar();
                });
            }
        })
    }
});
