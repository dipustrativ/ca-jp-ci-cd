<?php
/**
 * Mageplaza
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Mageplaza.com license that is
 * available through the world-wide-web at this URL:
 * https://www.mageplaza.com/LICENSE.txt
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Mageplaza
 * @package     Mageplaza_Osc
 * @copyright   Copyright (c) Mageplaza (https://www.mageplaza.com/)
 * @license     https://www.mageplaza.com/LICENSE.txt
 */

namespace Mageplaza\Osc\Model\Plugin\Checkout\Cart;

use Magento\Checkout\Model\Cart as ModelCart;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Message\MessageInterface;
use Magento\Framework\UrlInterface;
use Mageplaza\Osc\Helper\Data;

/**
 * Class Addgroup
 * @package Mageplaza\Osc\Model\Plugin\Checkout\Cart
 */
class Addgroup
{
    /**
     * @var Data
     */
    private $helper;

    /**
     * @var UrlInterface
     */
    private $url;

    /**
     * @var ModelCart
     */
    private $cart;

    /**
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * Addgroup constructor.
     *
     * @param Data $helper
     * @param UrlInterface $url
     * @param ModelCart $cart
     * @param ManagerInterface $messageManager
     */
    public function __construct(Data $helper, UrlInterface $url, ModelCart $cart, ManagerInterface $messageManager)
    {
        $this->helper = $helper;
        $this->url = $url;
        $this->cart = $cart;
        $this->messageManager = $messageManager;
    }

    /**
     * @param \Magento\Checkout\Controller\Cart\Addgroup $subject
     * @param $result
     *
     * @return mixed
     */
    public function afterExecute(\Magento\Checkout\Controller\Cart\Addgroup $subject, $result)
    {
        if (!$this->helper->isEnabled()
            || !$this->helper->isRedirectToOneStepCheckout()
            || $this->cart->getQuote()->getHasError()
            || $this->messageManager->getMessages()->getCountByType(MessageInterface::TYPE_ERROR)
        ) {
            return $result;
        }

        $result->setUrl($this->url->getUrl($this->helper->getOscRoute()));

        return $result;
    }
}
