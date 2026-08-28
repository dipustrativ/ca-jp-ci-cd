<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Controller\SimilarTo;

use CarpetAvenue\Productify\Block\Product\List\Similar;
use Magento\Catalog\Block\Product\ProductList\Item\Container;
use Magento\Catalog\Pricing\Price\FinalPrice;
use \Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\LayoutFactory;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Wishlist\Block\Catalog\Product\ProductList\Item\AddTo\Wishlist;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;

class Ajax extends Action
{
    public function __construct(
        Context                         $context,
        protected RawFactory            $_rowFactory,
        protected LayoutFactory         $_layoutFactory,
        protected PageFactory           $_pageFactory,
        protected StoreManagerInterface $_storeManager,
        protected CacheInterface        $cache,
        protected ResourceConnection    $resourceConnection
    )
    {
        parent::__construct($context);
    }

    public function execute(): ResultInterface|ResponseInterface|Raw
    {
        $productId = (int)$this->getRequest()->getParam('product');

        if (!$productId) {
            return $this->_rowFactory->create()->setContents('');
        }

        /*
         * HTML caching is deliberately OFF here.
         *
         * The rendered markup embeds prices (setPriceHtml below), which are
         * customer-group and tax dependent, and the key was only scoped by
         * product + store — so a cached response would serve one group's prices
         * to every other group. The read-back was commented out for that reason
         * but the write was left in, which meant every product page still paid a
         * ~40 KB json_encode + cache write whose result nothing ever read.
         *
         * To turn caching back on: add the customer group id (and the tax/VAT
         * scope this store varies prices by) to the key, then restore the
         * load()/isCacheStillValid() branch — isCacheStillValid() below is the
         * stock guard it used.
         */
        $this->getResponse()->setHeader('X-Cache-Productify-Similar-Listing', 'MISS');

        $resultPage = $this->_pageFactory->create();
        $layout = $resultPage->getLayout();

        $similarBlock = $layout->createBlock(Similar::class, 'similar.products.ajax')
            ->setTemplate('CarpetAvenue_Productify::product/list/similar_product_grid.phtml')
            ->setData('product_id', $productId)
            ->setData('type', 'similar');

        $addToBlock = $layout->createBlock(Container::class, 'similar.products.addto');
        $wishlistBlock = $layout->createBlock(Wishlist::class, 'similar.products.addto.wishlist')
            ->setTemplate('Magento_Wishlist::catalog/product/list/addto/wishlist.phtml');

        $addToBlock->setChild('wishlist', $wishlistBlock);
        $similarBlock->setChild('addto', $addToBlock);


        $items = $similarBlock->getItems();

        // Price rendering — only if block is available
        $priceRender = $layout->getBlock('product.price.render.default');
        if ($priceRender) {
            foreach ($items as $product) {
                $product->setPriceHtml(
                    $priceRender->render(FinalPrice::PRICE_CODE, $product, ['include_container' => true])
                );
            }
        }

        return $this->_rowFactory->create()->setContents($similarBlock->toHtml());
    }

    private function isCacheStillValid(array $productIds): bool
    {
        if (empty($productIds)) {
            return true;
        }

        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('cataloginventory_stock_status'),
                'COUNT(*)'
            )
            ->where('product_id IN (?)', $productIds)
            ->where('stock_status = ?', 0);

        $outOfStockProducts = (int)$connection->fetchOne($select);

        // If any product is out of stock, invalidate cache
        return $outOfStockProducts === 0;
    }
}
