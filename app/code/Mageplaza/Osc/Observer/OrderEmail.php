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
use Magento\Framework\Validator\Exception;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Address;
use Mageplaza\Osc\Helper\Data as OscHelper;

/**
 * Class OrderEmail
 * @package Mageplaza\Osc\Observer
 */
 class OrderEmail implements ObserverInterface
{
    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var OscHelper
     */
    protected $_oscHelper;

    /**
     * OrderEmail constructor.
     *
     * @param OrderRepositoryInterface $orderRepository
     * @param OscHelper                $oscHelper
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        OscHelper                $oscHelper
    ) {
        $this->orderRepository  = $orderRepository;
        $this->_oscHelper       = $oscHelper;
    }

    /**
     * @param Observer $observer
     *
     * @throws Exception
     */
    public function execute(Observer $observer)
    {
        if (method_exists($observer,'getData')) {
            $transport            = $observer->getData('transportObject');
            $shippingAddressMpOsc = [];
            $billingAddressMpOsc  = [];
            $listMpOsc            = [
                ['code' => 'mposc_field_1', 'value' => '1'],
                ['code' => 'mposc_field_2', 'value' => '2'],
                ['code' => 'mposc_field_3', 'value' => '3'],
            ];

            $orderId = $transport['order']->getId();
            $order   = $this->orderRepository->get($orderId);

            foreach ($listMpOsc as $item) {
                $attributeCode = $item['code'];
                $label         = $this->_oscHelper->getCustomFieldLabel($item['value']);

                $this->processAddressData($order->getShippingAddress(), $shippingAddressMpOsc, $attributeCode, $label);
                $this->processAddressData($order->getBillingAddress(), $billingAddressMpOsc, $attributeCode, $label);
            }

            $transport['osc_shipping_address'] = $shippingAddressMpOsc;
            $transport['osc_billing_address']  = $billingAddressMpOsc;
        }
    }
    /**
     * @param Address $address
     * @param array $addressMpOsc
     * @param string $attributeCode
     * @param string $label
     */
    protected function processAddressData($address, &$addressMpOsc, $attributeCode, $label)
    {
        $value = $address->getData($attributeCode);
        $addressMpOsc[$attributeCode] = $value;
        $addressMpOsc[$attributeCode . '_label'] = $label;
    }
}
