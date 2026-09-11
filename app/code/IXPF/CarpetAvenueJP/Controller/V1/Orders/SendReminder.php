<?php
declare(strict_types=1);

namespace IXPF\CarpetAvenueJP\Controller\V1\Orders;

use CarpetAvenue\Orderify\Model\Email\OrderReminderSender;
use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ActivityLogger;
use IXPF\Syncer\Helper\Logger\OrderActionLogger;
use IXPF\Syncer\Helper\TokenValidator;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

class SendReminder extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const COMMENT_FOR_ORDER_REMINDER = 'Reminder email is sent';

    public function __construct(
        Context                                     $context,
        TokenValidator                              $tokenValidator,
        JsonFactory                                 $resultFactory,
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly OrderReminderSender      $orderReminderSender,
        protected readonly OrderActionLogger      $orderLogger,
        protected readonly ActivityLogger          $activityLogger
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
            $this->orderLogger->writeDaily("[send_reminder] Received send reminder request for Order ID: $orderId");

            $order = $this->orderRepository->get($orderId);

            if ($order->getState() !== Order::STATE_NEW) {
                throw new Exception(
                    "Order #{$order->getIncrementId()} can only be Send Order Reminder in the 'new' state. "
                    . "Current state: {$order->getState()}, status: {$order->getStatus()}"
                );
            }

            $sent = $this->orderReminderSender->send($order);

            if (!$sent) {
                throw new Exception("Could not send reminder email for order #$orderId");
            }

            $order->addStatusToHistory(
                $order->getStatus(),
                self::COMMENT_FOR_ORDER_REMINDER,
                true
            );
            $this->orderRepository->save($order);

            $this->orderLogger->writeDaily(
                "[send_reminder] Reminder email sent for Order #{$order->getIncrementId()} to {$order->getCustomerEmail()}"
            );

            $this->activityLogger->success(
                'send_reminder',
                'order',
                $order->getIncrementId(),
                "Reminder email sent to {$order->getCustomerEmail()}"
            );

            return $this->jsonResponse([
                'success'      => true,
                'order_id'     => $orderId,
                'increment_id' => $order->getIncrementId(),
                'message'      => "Reminder email sent for order #$orderId",
            ]);
        } catch (Exception $exception) {
            $this->orderLogger->writeDaily('[send_reminder] Error sending reminder: ' . $exception->getMessage(), 'error');
            $this->activityLogger->error('send_reminder', 'order', $orderId ?? null, $exception->getMessage());
            return $this->jsonResponse([
                'error' => $exception->getMessage(),
            ], 500);
        }
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
