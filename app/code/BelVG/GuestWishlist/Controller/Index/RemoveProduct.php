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
namespace BelVG\GuestWishlist\Controller\Index;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Action;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Controller\ResultFactory;
/**
 * Class RemoveProduct
 * @package BelVG\GuestWishlist\Controller\Index
 */
class RemoveProduct extends AbstractIndex
{
    /**
     * @var \BelVG\GuestWishlist\Controller\WishlistProviderInterface
     */
    protected $wishlistProvider;

    /**
     * @var Validator
     */
    protected $formKeyValidator;

    /**
     * @var \BelVG\GuestWishlist\Controller\WishlistProviderInterface
     */
    protected $guestWishlistProvider;

    /**
     * @var Session
     */
    protected $session;

    /**
     * RemoveProduct constructor.
     * @param Action\Context $context
     * @param Session $session
     * @param \BelVG\GuestWishlist\Controller\WishlistProviderInterface $guestWishlistProvider
     * @param \Magento\Wishlist\Controller\WishlistProviderInterface $wishlistProvider
     * @param Validator $formKeyValidator
     */
    public function __construct(
        Action\Context $context,
        Session $session,
        \BelVG\GuestWishlist\Controller\WishlistProviderInterface $guestWishlistProvider,
        \Magento\Wishlist\Controller\WishlistProviderInterface $wishlistProvider,
        Validator $formKeyValidator
    ) {
        $this->session = $session;
        $this->guestWishlistProvider = $guestWishlistProvider;
        $this->wishlistProvider = $wishlistProvider;
        $this->formKeyValidator = $formKeyValidator;
        parent::__construct($context);
    }

    /**
     * Remove item
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     * @throws NotFoundException
     */
    public function execute()
    {
        /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        if (!$this->formKeyValidator->validate($this->getRequest())) {
            return $resultRedirect->setPath('*/*/');
        }

        if($this->session->isLoggedIn()){
            return $this->removeProduct($resultRedirect);
        } else {
            return $this->removeGuestProduct($resultRedirect);
        }
    }

    protected function removeGuestProduct($resultRedirect)
    {
        $id = (int)$this->getRequest()->getParam('product');
        $item = $this->_objectManager->create(\BelVG\GuestWishlist\Model\Item::class)->load($id, 'product_id');
        if (!$item->getId()) {
            throw new NotFoundException(__('Page not found.'));
        }
        $wishlist = $this->guestWishlistProvider->getWishlist($item->getWishlistId());
        if (!$wishlist) {
            throw new NotFoundException(__('Page not found.'));
        }
        try {
            $item->delete();
            $wishlist->save();
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->messageManager->addError(
                __('We can\'t delete the item from Wish List right now because of an error: %1.', $e->getMessage())
            );
        } catch (\Exception $e) {
            $this->messageManager->addError(__('We can\'t delete the item from the Wish List right now.'));
        }

        $this->_objectManager->get(\Magento\Wishlist\Helper\Data::class)->calculate();
        $request = $this->getRequest();
        $refererUrl = (string)$request->getServer('HTTP_REFERER');
        $url = (string)$request->getParam(\Magento\Framework\App\Response\RedirectInterface::PARAM_NAME_REFERER_URL);
        if ($url) {
            $refererUrl = $url;
        }
        if ($request->getParam(\Magento\Framework\App\ActionInterface::PARAM_NAME_URL_ENCODED) && $refererUrl) {
            $redirectUrl = $refererUrl;
        } else {
            $redirectUrl = $this->_redirect->getRedirectUrl($this->_url->getUrl('*/*'));
        }
        $resultRedirect->setUrl($redirectUrl);
        return $resultRedirect;
    }

    protected function removeProduct($resultRedirect)
    {
        $id = (int)$this->getRequest()->getParam('product');
        $item = $this->_objectManager->create(\Magento\Wishlist\Model\Item::class)->load($id, 'product_id');
        if (!$item->getId()) {
            throw new NotFoundException(__('Page not found.'));
        }
        $wishlist = $this->wishlistProvider->getWishlist($item->getWishlistId());
        if (!$wishlist) {
            throw new NotFoundException(__('Page not found.'));
        }
        try {
            $item->delete();
            $wishlist->save();
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->messageManager->addError(
                __('We can\'t delete the item from Wish List right now because of an error: %1.', $e->getMessage())
            );
        } catch (\Exception $e) {
            $this->messageManager->addError(__('We can\'t delete the item from the Wish List right now.'));
        }

        $this->_objectManager->get(\Magento\Wishlist\Helper\Data::class)->calculate();
        $request = $this->getRequest();
        $refererUrl = (string)$request->getServer('HTTP_REFERER');
        $url = (string)$request->getParam(\Magento\Framework\App\Response\RedirectInterface::PARAM_NAME_REFERER_URL);
        if ($url) {
            $refererUrl = $url;
        }
        if ($request->getParam(\Magento\Framework\App\ActionInterface::PARAM_NAME_URL_ENCODED) && $refererUrl) {
            $redirectUrl = $refererUrl;
        } else {
            $redirectUrl = $this->_redirect->getRedirectUrl($this->_url->getUrl('*/*'));
        }
        $resultRedirect->setUrl($redirectUrl);
        return $resultRedirect;
    }
}
