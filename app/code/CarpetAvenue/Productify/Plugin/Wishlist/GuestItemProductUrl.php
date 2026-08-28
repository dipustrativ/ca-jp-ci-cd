<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\Plugin\Wishlist;

use Magento\Catalog\Model\Product;
use Magento\Wishlist\Helper\Data as WishlistHelper;
use Magento\Wishlist\Model\Item as WishlistItem;

/**
 * BelVG guest-wishlist items are BelVG\GuestWishlist\Model\Item, which does not
 * extend Magento\Wishlist\Model\Item. Newer Magento typed the private
 * getItemQueryOptions(Item|Product) called from Helper\Data::getProductUrl(),
 * so rendering the guest wishlist page throws a TypeError.
 *
 * Resolve any non-core item to its product before the helper runs; the product
 * satisfies the type hint and yields the same URL.
 */
class GuestItemProductUrl
{
    /**
     * @param WishlistHelper $subject
     * @param mixed $item
     * @param array $additional
     * @return array
     */
    public function beforeGetProductUrl(WishlistHelper $subject, $item, $additional = [])
    {
        if (!$item instanceof WishlistItem && !$item instanceof Product) {
            $item = $item->getProduct();
        }

        return [$item, $additional];
    }
}
