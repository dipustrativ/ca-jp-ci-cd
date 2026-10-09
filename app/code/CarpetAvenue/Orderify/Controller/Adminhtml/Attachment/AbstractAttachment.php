<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Controller\Adminhtml\Attachment;

use CarpetAvenue\Orderify\Model\Attachment\AttachmentManager;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * Shared base for the admin order-view attachment actions.
 */
abstract class AbstractAttachment extends Action
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_Orderify::order_attachment';

    public function __construct(
        Context $context,
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly AttachmentManager $attachmentManager
    ) {
        parent::__construct($context);
    }

    /**
     * @throws LocalizedException
     */
    protected function loadOrder(): Order
    {
        $orderId = (int)$this->getRequest()->getParam('order_id');
        if ($orderId <= 0) {
            throw new LocalizedException(__('Order ID is required.'));
        }

        /** @var Order $order */
        $order = $this->orderRepository->get($orderId);

        return $order;
    }

    protected function redirectToOrder(int $orderId): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();

        return $orderId > 0
            ? $redirect->setPath('sales/order/view', ['order_id' => $orderId])
            : $redirect->setPath('sales/order/index');
    }
}
