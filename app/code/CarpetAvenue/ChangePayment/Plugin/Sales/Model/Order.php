<?php
namespace CarpetAvenue\ChangePayment\Plugin\Sales\Model;

class Order
{
    public function __construct(
        protected \CarpetAvenue\ChangePayment\Helper\Data $dataHelper,
        protected \CarpetAvenue\ChangePayment\Helper\Order $orderHelper
    ) {
    }
    public function afterUnhold(\Magento\Sales\Model\Order $context, \Magento\Sales\Model\Order $order)
    {
        // Custom logic after un-holding an order.
        if ($this->dataHelper->isEnabled()) {
            $this->orderHelper->deleteRetryReservations($order->getIncrementId());
        }
        return $order;
    }

    public function afterCancel(\Magento\Sales\Model\Order $order)
    {
        // Custom logic after canceling an order.
        if ($this->dataHelper->isEnabled()) {
            $this->orderHelper->deleteRetryReservations($order->getIncrementId());
        }
        return $order;
    }
}