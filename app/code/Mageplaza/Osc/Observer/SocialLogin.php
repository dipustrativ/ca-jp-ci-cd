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

namespace Mageplaza\Osc\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\App\RequestInterface;

/**
 * Class SocialLogin
 * @package Mageplaza\Osc\Observer
 */
class SocialLogin implements ObserverInterface
{
    /**
     * @var UrlInterface
     */
    protected $url;

    /**
     * SocialLogin constructor.
     * @param UrlInterface $url
     */
    public function __construct(
        UrlInterface $url
    ) {
        $this->url = $url;
    }

    /**
     * @param Observer $observer
     * @return $this|void
     */
    public function execute(Observer $observer)
    {
        $object = $observer->getEvent()->getObject();

        /** @var RequestInterface $request */
        $request = $observer->getEvent()->getRequest();
        $backUrl = $request->getParam('back_url');
        if ($backUrl) {
            $url = $this->url->getUrl($backUrl);
            $object->setUrl($url);
        }

        return $this;
    }
}
