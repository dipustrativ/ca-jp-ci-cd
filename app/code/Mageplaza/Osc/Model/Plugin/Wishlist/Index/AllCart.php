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

namespace Mageplaza\Osc\Model\Plugin\Wishlist\Index;

use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Message\MessageInterface;
use Magento\Framework\UrlInterface;
use Mageplaza\Osc\Helper\Data;

/**
 * Class AllCart
 * @package Mageplaza\Osc\Model\Plugin\Wishlist\Index
 */
class AllCart
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
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * AllCart constructor.
     *
     * @param Data $helper
     * @param UrlInterface $url
     * @param ManagerInterface $messageManager
     */
    public function __construct(Data $helper, UrlInterface $url, ManagerInterface $messageManager)
    {
        $this->helper = $helper;
        $this->url = $url;
        $this->messageManager = $messageManager;
    }

    /**
     * @param \Magento\Wishlist\Controller\Index\AllCart $subject
     * @param $result
     *
     * @return mixed
     */
    public function afterExecute(\Magento\Wishlist\Controller\Index\AllCart $subject, $result)
    {
        if ($result instanceof Forward
            || !$this->helper->isEnabled()
            || !$this->helper->isRedirectToOneStepCheckout()
            || !$this->messageManager->getMessages()->getCountByType(MessageInterface::TYPE_SUCCESS)
        ) {
            return $result;
        }

        return $result->setUrl($this->url->getUrl($this->helper->getOscRoute()));
    }
}
