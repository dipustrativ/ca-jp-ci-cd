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
 * Class Button
 * @package BelVG\GuestWishlist\Block\Wishlist
 */
class Button extends \Magento\Framework\View\Element\Template
{
    /**
     * Wishlist config
     *
     * @var \Magento\Wishlist\Model\Config
     */
    protected $_wishlistConfig;

    /**
     * Wishlist data
     *
     * @var \Magento\Wishlist\Helper\Data
     */
    protected $_wishlistData = null;

    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Wishlist\Helper\Data $wishlistData
     * @param \Magento\Wishlist\Model\Config $wishlistConfig
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Wishlist\Helper\Data $wishlistData,
        \Magento\Wishlist\Model\Config $wishlistConfig,
        array $data = []
    ) {
        $this->_wishlistData = $wishlistData;
        $this->_wishlistConfig = $wishlistConfig;
        parent::__construct($context, $data);
    }

    /**
     * Retrieve current wishlist
     *
     * @return \Magento\Wishlist\Model\Wishlist
     */
    public function getWishlist()
    {
        return $this->_wishlistData->getWishlist();
    }

    /**
     * Retrieve wishlist config
     *
     * @return \Magento\Wishlist\Model\Config
     */
    public function getConfig()
    {
        return $this->_wishlistConfig;
    }
}
