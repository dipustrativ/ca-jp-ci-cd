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
 * @package     Mageplaza_DeliveryTime
 * @copyright   Copyright (c) Mageplaza (https://www.mageplaza.com/)
 * @license     https://www.mageplaza.com/LICENSE.txt
 */

namespace Mageplaza\DeliveryTime\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Mageplaza\DeliveryTime\Helper\Data;

/**
 * Class OrderEmail
 * @package Mageplaza\DeliveryTime\Observer
 */
class OrderEmail implements ObserverInterface
{
    /**
     * @param Observer $observer
     */
    public function execute(Observer $observer)
    {
        $transport = $observer->getTransport();
        $order = &$transport['order'];
        if ($order->getMpDeliveryInformation()) {
            $mpDeliveryInformation = Data::jsonDecode($order->getMpDeliveryInformation());
            $order->addData($mpDeliveryInformation)->save();
        }
    }
}
