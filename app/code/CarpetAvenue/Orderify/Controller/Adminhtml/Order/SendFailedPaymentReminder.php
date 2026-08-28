<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Order
 * @package   CarpetAvenue_Orderify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Controller\Adminhtml\Order;

use CarpetAvenue\Orderify\Model\Email\FailedPaymentSender;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

class SendFailedPaymentReminder extends Action
{
    public const ADMIN_RESOURCE = 'Magento_Sales::actions_view';

    public function __construct(
        Context                                   $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly FailedPaymentSender      $failedPaymentSender
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $orderId        = (int) $this->getRequest()->getParam('order_id');

        if (!$orderId) {
            $this->messageManager->addErrorMessage(__('Order ID is missing.'));
            return $resultRedirect->setPath('sales/order/index');
        }

        try {
            /** @var Order $order */
            $order = $this->orderRepository->get($orderId);

            $unsaleableItems = $this->getUnsaleableItems($order);
            if (!empty($unsaleableItems)) {
                $this->messageManager->addWarningMessage(__(
                    'Cannot send reminder. The following product(s) are no longer available for purchase: %1',
                    implode(', ', $unsaleableItems)
                ));
                return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
            }

            $sent = $this->failedPaymentSender->send($order);

            if ($sent) {
                $this->messageManager->addSuccessMessage(
                    __('Failed payment reminder email sent to %1.', $order->getCustomerEmail())
                );
            } else {
                $this->messageManager->addWarningMessage(
                    __('Failed payment reminder email is disabled or could not be sent.')
                );
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(
                __('Could not send reminder: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
    }

    /**
     * Returns names of order items whose products are no longer saleable.
     *
     * @return string[]
     */
    private function getUnsaleableItems(Order $order): array
    {
        $unsaleable = [];

        foreach ($order->getAllVisibleItems() as $item) {
            try {
                $product = $this->productRepository->getById(
                    (int) $item->getProductId(),
                    false,
                    $order->getStoreId()
                );

                if ($product->getStatus() == 2) {
                    $unsaleable[] = sprintf('%s (SKU: %s) — product disabled', $item->getName(), $item->getSku());
                    continue;
                }
                if ($product->getData('online') != 1) {
                    $unsaleable[] = sprintf('%s (SKU: %s) — product not available online', $item->getName(), $item->getSku());
                    continue;
                }

                if (!$product->isSaleable()) {
                    $unsaleable[] = sprintf('%s (SKU: %s)', $item->getName(), $item->getSku());
                }
            } catch (NoSuchEntityException) {
                // product deleted — treat as not saleable
                $unsaleable[] = sprintf('%s (SKU: %s) — product deleted', $item->getName(), $item->getSku());
            }
        }

        return $unsaleable;
    }
}
