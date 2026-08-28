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
namespace BelVG\GuestWishlist\Block\Wishlist;

/**
 * Class Items
 * @package BelVG\GuestWishlist\Block\Wishlist
 */
class Items extends \Magento\Framework\View\Element\Template
{
    /**
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getColumns()
    {
        $columns = [];
        foreach ($this->getLayout()->getChildBlocks($this->getNameInLayout()) as $child) {
            if ($child instanceof \BelVG\GuestWishlist\Block\Wishlist\Item\Column && $child->isEnabled()) {
                $columns[] = $child;
            }
        }
        return $columns;
    }
}
