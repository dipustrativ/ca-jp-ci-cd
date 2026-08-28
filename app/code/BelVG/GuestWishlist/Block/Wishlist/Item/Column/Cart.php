<?php
/**
 * BelVG LLC.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the EULA
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://store.belvg.com/BelVG-LICENSE-COMMUNITY.txt
 *
 ********************************************************************
 * @category   BelVG
 * @package    BelVG_GuestWishlist
 * @copyright  Copyright (c) BelVG LLC. (http://www.belvg.com)
 * @license    http://store.belvg.com/BelVG-LICENSE-COMMUNITY.txt
 */
namespace BelVG\GuestWishlist\Block\Wishlist\Item\Column;

/**
 * Class Cart
 * @package BelVG\GuestWishlist\Block\Wishlist\Item\Column
 */
class Cart extends \BelVG\GuestWishlist\Block\Wishlist\Item\Column
{
    /**
     * Returns qty to show visually to user
     *
     * @param mixed $item
     * @return float
     */
    public function getAddToCartQty($item)
    {
        $qty = $item->getQty();
        return $qty ? $qty : 1;
    }

    /**
     * Return product for current item
     *
     * @return \Magento\Catalog\Model\Product
     */
    public function getProductItem()
    {
        return $this->getItem()->getProduct();
    }
}
