<?php

namespace Evalent\OrderMods\Controller\Adminhtml\Order;


class Cancellation extends \Magento\Sales\Controller\Adminhtml\Order
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    const ADMIN_RESOURCE = 'Magento_Sales::cancellation';

    const COMMENT_FOR_ORDER_CANCELLATION = 'Cancellation email is sent';

    public function execute()
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $this->_initOrder();
        if ($order) {
            try {
                $orderCancellationSender = $this->_objectManager
                    ->create(\Evalent\OrderMods\Model\Order\Email\Sender\OrderCancellationSender::class);

                $orderCancellationSender->send($order);
                $order->addStatusToHistory(
                    $order->getStatus(),
                    self::COMMENT_FOR_ORDER_CANCELLATION,
                    true
                );
                $this->orderManagement->cancel($order->getEntityId());
                $this->orderRepository->save($order);
                $this->messageManager->addSuccessMessage(__('You sent the order cancellation email.'));
                return $this->resultRedirectFactory->create()->setPath(
                    'sales/order/view',
                    [
                        'order_id' => $order->getEntityId()
                    ]
                );
            } catch (\Exception $e) {
                $response = ['error' => true, 'message' => $e->getMessage()];
            }
            if (is_array($response)) {
                $resultJson = $this->resultJsonFactory->create();
                $resultJson->setData($response);
                return $resultJson;
            }
        }

        return $this->resultRedirectFactory->create()->setPath('sales/*/');
    }
}
