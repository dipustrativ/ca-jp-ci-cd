<?php

declare(strict_types=1);

namespace Evalent\InvoicePaymentMods\Plugin\Helper;

use Magento\Sales\Api\OrderRepositoryInterface;

class Data
{


    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private $orderRepository;

    public function __construct(
        OrderRepositoryInterface $orderRepository
    ) {
        $this->orderRepository = $orderRepository;
    }

    /**
     * @param \Evalent\InvoicePaymentMods\Helper\Data $subject
     * @param callable                                  $proceed
     * @param \Magento\Sales\Model\Order                $order
     */
    public function aroundCreateInvoice(
        \Evalent\InvoicePaymentMods\Helper\Data $subject,
        callable $proceed,
        $order
    ) {
        $result = $proceed($order);
        if ($result != null) {
            $orderStatus = $subject->getConfigField('order_status');

            if (!$orderStatus) {
                return $result;
            }
            $order->setStatus($orderStatus);
            $this->orderRepository->save($order);
        }
        return $result;
    }
}