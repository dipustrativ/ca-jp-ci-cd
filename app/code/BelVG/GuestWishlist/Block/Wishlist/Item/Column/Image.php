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
declare(strict_types=1);

namespace BelVG\GuestWishlist\Block\Wishlist\Item\Column;
use Magento\Framework\App\ObjectManager;
/**
 * Class Image
 * @package BelVG\GuestWishlist\Block\Wishlist\Item\Column
 */
class Image extends \BelVG\GuestWishlist\Block\Wishlist\Item\Column
{
    /**
     * Identify the product from which thumbnail should be taken.
     *
     * @return \Magento\Catalog\Model\Product
     */
    public function getProductForThumbnail(\BelVG\GuestWishlist\Model\Item $item) : \Magento\Catalog\Model\Product
    {
        $itemResolver  = ObjectManager::getInstance()->get('\Magento\Catalog\Model\Product\Configuration\Item\ItemResolverInterface');
        return $itemResolver->getFinalProduct($item);
    }
}
