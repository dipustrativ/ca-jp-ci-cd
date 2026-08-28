<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Wishlist block customer items
 *
 * @author     Magento Core Team <core@magentocommerce.com>
 */
namespace BelVG\GuestWishlist\Block\Share\Email;

use BelVG\GuestWishlist\Model\Item;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Configuration\Item\ItemResolverInterface;
use Magento\Catalog\Model\Product\Image\UrlBuilder;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\View\ConfigInterface;

/**
 * @api
 * @since 100.0.2
 */
class Items extends \Magento\Wishlist\Block\Share\Email\Items
{
    /**
     * @var string
     */
    protected $_template = 'Magento_Wishlist::email/items.phtml';

    /**
     * @var ItemResolverInterface|null
     */
    protected ?ItemResolverInterface $itemResolver;


    public function __construct(
        \Magento\Catalog\Block\Product\Context $context,
        \Magento\Framework\App\Http\Context $httpContext,
        array $data = [],
        ?ConfigInterface $config = null,
        ?UrlBuilder $urlBuilder = null,
        ?ItemResolverInterface $itemResolver = null
    ) {
        parent::__construct($context, $httpContext, $data, $config, $urlBuilder,
            $itemResolver);
        $this->itemResolver = $itemResolver ?? ObjectManager::getInstance()->get(ItemResolverInterface::class);
    }

    /**
     * Retrieve Product View URL
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param array $additional
     * @return string
     */
    public function getProductUrl($product, $additional = [])
    {
        $additional['_scope_to_url'] = true;
        return parent::getProductUrl($product, $additional);
    }


    /**
     * Identify the product from which thumbnail should be taken.
     *
     * @param Item $item
     *
     * @return Product
     * @since 101.2.0
     */
    public function getProductForThumbnail($item): Product
    {
        return $this->itemResolver->getFinalProduct($item);
    }


    /**
     * Retrieve URL for add product to shopping cart
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param array $additional
     * @return string
     */
    public function getAddToCartUrl($product, $additional = [])
    {
        $additional['nocookie'] = 1;
        $additional['_scope_to_url'] = true;
        return parent::getAddToCartUrl($product, $additional);
    }

    /**
     * Check whether wishlist item has description
     *
     * @param \Magento\Wishlist\Model\Item $item
     * @return bool
     */
    public function hasDescription($item)
    {
        $hasDescription = parent::hasDescription($item);
        if ($hasDescription) {
            return $item->getDescription() !== $this->_wishlistHelper->defaultCommentString();
        }
        return $hasDescription;
    }
}
