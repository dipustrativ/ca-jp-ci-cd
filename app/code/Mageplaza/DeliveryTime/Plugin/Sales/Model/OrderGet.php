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

namespace Mageplaza\DeliveryTime\Plugin\Sales\Model;

use Magento\Sales\Api\Data\OrderExtension;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\Data\OrderExtensionFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\ResourceModel\Order\Collection;
use Mageplaza\DeliveryTime\Helper\Data;

/**
 * Class OrderGet
 * @package Mageplaza\DeliveryTime\Plugin\Sales\Model
 */
class OrderGet
{
    /**
     * @var OrderExtensionFactory
     */
    protected $orderExtensionFactory;

    /**
     * OrderGet constructor.
     *
     * @param OrderExtensionFactory $orderExtensionFactory
     */
    public function __construct(
        OrderExtensionFactory $orderExtensionFactory
    ) {
        $this->orderExtensionFactory = $orderExtensionFactory;
    }

    /**
     * @param OrderRepositoryInterface $subject
     * @param OrderInterface $resultOrder
     *
     * @return OrderInterface
     */
    public function afterGet(
        OrderRepositoryInterface $subject,
        OrderInterface $resultOrder
    ) {
        if ($resultOrder->getMpDeliveryInformation()) {
            $extensionAttributes   = $resultOrder->getExtensionAttributes();
            $mpDeliveryInformation = Data::jsonDecode($resultOrder->getMpDeliveryInformation());
            $mpDeliveryDate        = isset($mpDeliveryInformation['deliveryDate'])
                ? $mpDeliveryInformation['deliveryDate'] : null;
            $mpDeliveryTime        = isset($mpDeliveryInformation['deliveryTime'])
                ? $mpDeliveryInformation['deliveryTime'] : null;
            $mpHouseSecurityCode   = isset($mpDeliveryInformation['houseSecurityCode'])
                ? $mpDeliveryInformation['houseSecurityCode'] : null;
            $mpDeliveryComment     = isset($mpDeliveryInformation['deliveryComment'])
                ? $mpDeliveryInformation['deliveryComment'] : null;
            /** @var OrderExtension $orderExtension */
            $orderExtension = $extensionAttributes ? $extensionAttributes : $this->orderExtensionFactory->create();
            $orderExtension->setMpDeliveryDate($mpDeliveryDate);
            $orderExtension->setMpDeliveryTime($mpDeliveryTime);
            $orderExtension->setMpHouseSecurityCode($mpHouseSecurityCode);
            $orderExtension->setMpDeliveryComment($mpDeliveryComment);
            $resultOrder->setExtensionAttributes($orderExtension);
        }

        return $resultOrder;
    }

    /**
     * @param OrderRepositoryInterface $subject
     * @param Collection $resultOrder
     *
     * @return Collection
     */
    public function afterGetList(
        OrderRepositoryInterface $subject,
        Collection $resultOrder
    ) {
        /** @var  OrderInterface $order */
        foreach ($resultOrder->getItems() as $order) {
            $this->afterGet($subject, $order);
        }

        return $resultOrder;
    }
}
