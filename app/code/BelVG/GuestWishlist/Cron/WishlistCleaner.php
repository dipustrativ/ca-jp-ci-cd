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
namespace BelVG\GuestWishlist\Cron;

use BelVG\GuestWishlist\Model\WishlistFactory;

/**
 * Class WishlistCleaner
 * @package BelVG\GuestWishlist\Cron
 */
class WishlistCleaner {

    /**
     * @var WishlistFactory
     */
    protected $wishlistFactory;

    /**
     * @var \BelVG\GuestWishlist\Helper\Data
     */
    protected $helper;

    /**
     * WishlistCleaner constructor.
     * @param \BelVG\GuestWishlist\Helper\Data $helper
     * @param WishlistFactory $wishlistFactory
     */
    public function __construct(
        \BelVG\GuestWishlist\Helper\Data $helper,
        WishlistFactory $wishlistFactory
    )
    {
        $this->helper = $helper;
        $this->wishlistFactory = $wishlistFactory;
    }

    /**
     * @return $this
     */
    public function execute()
    {
        $clearTime = date ( 'Y-m-d', strtotime('-'.$this->helper->getCookieLifeTime().'days') );
        $collection = $this->wishlistFactory->create()
            ->getCollection()
            ->addFieldToFilter('updated_at',
                [
                    'lteq' =>$clearTime.' 00:00:00',
                ]
            );
        $collection->walk('delete');
        return $this;
    }
}