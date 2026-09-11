<?php
declare(strict_types=1);

namespace IXPF\CarpetAvenueJP\Controller\V1\Orders;

use CarpetAvenue\Orderify\Model\Email\FailedPaymentSender;
use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ActivityLogger;
use IXPF\Syncer\Helper\Logger\OrderActionLogger;
use IXPF\Syncer\Helper\TokenValidator;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

class SendFailedPaymentReminder extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context                                       $context,
        TokenValidator                                $tokenValidator,
        JsonFactory                                   $resultFactory,
        protected readonly OrderRepositoryInterface   $orderRepository,
        protected readonly ProductRepositoryInterface $productRepository,
        protected readonly FailedPaymentSender        $failedPaymentSender,
        protected readonly OrderActionLogger               $orderLogger,
        protected readonly ActivityLogger             $activityLogger
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            $this->tokenValidator();

            $orderId = $this->getRequest()->getParam('id');
            if (empty($orderId)) {
                throw new Exception('order_id is required');
            }

            $this->orderLogger->writeDaily("[failed_payment_reminder] Received failed payment reminder request for Order ID: $orderId");

            /** @var Order $order */
            $order = $this->orderRepository->get($orderId);

            if ($order->getState() !== Order::STATE_CANCELED) {
                throw new Exception(
                    "Order #{$order->getIncrementId()} failed payment reminder can only be sent in the 'canceled' state. "
                    . "Current state: {$order->getState()}, status: {$order->getStatus()}"
                );
            }

            $unsaleableItems = $this->getUnsaleableItems($order);
            if (!empty($unsaleableItems)) {
                $this->orderLogger->writeDaily(
                    "[failed_payment_reminder] Blocked for Order #{$order->getIncrementId()} — unsaleable items: "
                    . implode('; ', $unsaleableItems),
                    'warning'
                );
                $this->activityLogger->error(
                    'failed_payment_reminder',
                    'order',
                    $order->getIncrementId(),
                    'Blocked — unsaleable items: ' . implode('; ', $unsaleableItems)
                );
                return $this->jsonResponse([
                    'success'          => false,
                    'order_id'         => $orderId,
                    'error'            => 'Cannot send reminder. The following product(s) are no longer available for purchase.',
                    'unsaleable_items' => $unsaleableItems,
                ], 422);
            }

            $sent = $this->failedPaymentSender->send($order);

            if (!$sent) {
                throw new Exception("Failed payment reminder email is disabled or could not be sent for order #$orderId");
            }

            $this->orderLogger->writeDaily(
                "[failed_payment_reminder] Reminder sent for Order #{$order->getIncrementId()} to {$order->getCustomerEmail()}"
            );

            $this->activityLogger->success(
                'failed_payment_reminder',
                'order',
                $order->getIncrementId(),
                "Reminder sent to {$order->getCustomerEmail()}"
            );

            return $this->jsonResponse([
                'success'      => true,
                'order_id'     => $orderId,
                'increment_id' => $order->getIncrementId(),
                'message'      => "Failed payment reminder email sent to {$order->getCustomerEmail()}",
            ]);
        } catch (Exception $exception) {
            $this->orderLogger->writeDaily('[failed_payment_reminder] Error sending reminder: ' . $exception->getMessage(), 'error');
            $this->activityLogger->error('failed_payment_reminder', 'order', $orderId ?? null, $exception->getMessage());
            return $this->jsonResponse([
                'error' => $exception->getMessage(),
            ], 500);
        }
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

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
