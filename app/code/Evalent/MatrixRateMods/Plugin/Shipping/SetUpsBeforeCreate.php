<?php

declare(strict_types=1);

namespace Evalent\MatrixRateMods\Plugin\Shipping;

use Evalent\MatrixRateMods\Controller\Adminhtml\Update\Index;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Shipping\Controller\Adminhtml\Order\Shipment\NewAction;

class SetUpsBeforeCreate
{

    /**
     * @var \Evalent\MatrixRateMods\Controller\Adminhtml\Update\Index
     */
    private $updateShippingMods;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private $orderRepository;

    public function __construct(
        Index $updateShippingMods,
        OrderRepositoryInterface $orderRepository
    ) {

        $this->updateShippingMods = $updateShippingMods;
        $this->orderRepository = $orderRepository;
    }


    public function beforeExecute(NewAction $subject)
    {
        try {
            $orderId = $subject->getRequest()->getParam('order_id');
            $order = $this->orderRepository->get($orderId);
            if (mb_strpos($order->getShippingMethod(), "matrixrate") !== false) {
                $this->updateShippingMods->updateShippingMethod($orderId, "ups_11");
            }
        } catch (\Exception $e) {

        }
    }
}