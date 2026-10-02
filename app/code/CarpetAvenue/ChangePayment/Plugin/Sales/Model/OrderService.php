<?php
namespace CarpetAvenue\ChangePayment\Plugin\Sales\Model;

class OrderService
{
    public function __construct(
        protected \CarpetAvenue\ChangePayment\Helper\Data $dataHelper,
        protected \CarpetAvenue\ChangePayment\Helper\Order $orderHelper
    ) {
    }
    public function afterPlace(\Magento\Sales\Model\Service\OrderService $context, \Magento\Sales\Model\Order $order)
    {
        // Custom logic can be added here after the order is placed.
        // For example, logging or modifying the order object.
        if ($order->getData('holded_order_id') && $this->dataHelper->isEnabled()) {
            // If the order has an original order ID, it could mean it's a re-order.
            // You can add custom logic here if needed.
            $this->orderHelper->cancelAfterNewOrder($order->getData('holded_order_id'));
        }
        return $order;
    }
}