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
namespace BelVG\GuestWishlist\Controller;

use BelVG\GuestWishlist\Helper\Data;
use Magento\Framework\App\RequestInterface;

/**
 * Class WishlistProvider
 * @package BelVG\GuestWishlist\Controller
 */
class WishlistProvider implements WishlistProviderInterface
{
    /**
     * @var \BelVG\GuestWishlist\Model\Wishlist
     */
    protected $wishlist;

    /**
     * @var \BelVG\GuestWishlist\Model\WishlistFactory
     */
    protected $wishlistFactory;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $request;

    /**
     * @var Data
     */
    protected $dataHelper;

    /**
     * WishlistProvider constructor.
     * @param \BelVG\GuestWishlist\Model\WishlistFactory $wishlistFactory
     * @param Data $dataHelper
     * @param \Magento\Framework\Message\ManagerInterface $messageManager
     * @param RequestInterface $request
     */
    public function __construct(
        \BelVG\GuestWishlist\Model\WishlistFactory $wishlistFactory,
        \BelVG\GuestWishlist\Helper\Data $dataHelper,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        RequestInterface $request
    ) {
        $this->request = $request;
        $this->wishlistFactory = $wishlistFactory;
        $this->messageManager = $messageManager;
        $this->dataHelper = $dataHelper;
    }

    /**
     * {@inheritdoc}
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function getWishlist()
    {
        if ($this->wishlist) {
            return $this->wishlist;
        }
        try {
            $guestCustomerId = $this->dataHelper->getGuestCustomerId();
            $wishlist = $this->wishlistFactory->create();
            $wishlist->loadByGuestCustomerId($guestCustomerId, true);
            if (!$wishlist->getId() || $wishlist->getGuestCustomerId() != $guestCustomerId) {
                throw new \Magento\Framework\Exception\NoSuchEntityException(
                    __('The requested Wish List doesn\'t exist.')
                );
            }
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->messageManager->addError($e->getMessage());
            return false;
        } catch (\Exception $e) {
            $this->messageManager->addException($e, __('We can\'t create the Guest Wish List right now.'));
            return false;
        }
        $this->wishlist = $wishlist;
        return $wishlist;
    }
}
