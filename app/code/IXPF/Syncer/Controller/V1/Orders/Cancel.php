<?php
declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Orders;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ActivityLogger;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\OrderActionLogger;
use IXPF\Syncer\Helper\TokenValidator;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * Cancel an order.
 *
 * POST /ixpfsyncer/V1/Orders/Cancel?id=<order_id>
 *
 * Only orders in the 'new' state may be cancelled — any other state is rejected.
 *
 * Always adds status history note "Order canceled by IXPF API".
 *
 * Optional JSON body:
 *   comment: string             extra text appended to the history note
 *   visible_storefront: bool    show comment to customer (default false)
 *
 * Uses Order::cancel() which restores reserved stock (MSI or legacy
 * CatalogInventory), sets qty_canceled on each item and fires the
 * sales_order_cancel_after event.
 */
class Cancel extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected readonly Config $config,
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly OrderActionLogger $orderLogger,
        protected readonly ActivityLogger $activityLogger
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            $this->tokenValidator();

            $id = $this->getRequest()->getParam('id');
            if (!$id) {
                throw new Exception('Order ID is required');
            }

            if (!$this->config->isOrderUpdateEnabled()) {
                throw new Exception('Order is disabled in configuration');
            }

            $this->orderLogger->writeDaily("[cancel] Received cancel request for Order ID: $id");

            try {
                /** @var Order $order */
                $order = $this->orderRepository->get((int) $id);
            } catch (NoSuchEntityException) {
                throw new Exception("Order #$id not found");
            }

            if ($order->getState() !== Order::STATE_NEW) {
                throw new Exception(
                    "Order #{$order->getIncrementId()} can only be cancelled in the 'new' state. "
                    . "Current state: {$order->getState()}, status: {$order->getStatus()}"
                );
            }

            if (!$order->canCancel()) {
                throw new Exception(
                    "Order #{$order->getIncrementId()} cannot be cancelled. "
                    . "State: {$order->getState()}, status: {$order->getStatus()}"
                );
            }

            $payload = json_decode($this->getRequest()->getContent() ?: '', true) ?: [];
            $comment = trim((string) ($payload['comment'] ?? ''));
            $visibleOnStorefront = !empty($payload['visible_storefront']);

            $this->orderLogger->writeDaily('[cancel] Payload: ' . json_encode([
                'comment' => $comment,
                'visible_storefront' => $visibleOnStorefront,
            ]));

            $order->cancel();
            $historyComment = 'Order canceled by IXPF API'
                . ($comment !== '' ? ' — ' . $comment : '');
            $order->addCommentToStatusHistory(
                $historyComment,
                Order::STATE_CANCELED,
                $visibleOnStorefront
            );
            $this->orderRepository->save($order);

            $this->orderLogger->writeDaily(
                "[cancel] Order #{$order->getIncrementId()} cancelled — status: {$order->getStatus()}"
            );

            $this->activityLogger->success(
                'cancel',
                'order',
                $order->getIncrementId(),
                "Order #{$order->getIncrementId()} cancelled"
            );

            return $this->jsonResponse([
                'success' => true,
                'message' => "Order #{$order->getIncrementId()} cancelled",
                'data'    => [
                    'order_id'     => (int) $order->getEntityId(),
                    'increment_id' => $order->getIncrementId(),
                    'state'        => $order->getState(),
                    'status'       => $order->getStatus(),
                ],
            ]);
        } catch (Exception $exception) {
            $this->orderLogger->writeDaily('[cancel] Error cancelling order: ' . $exception->getMessage(), 'error');
            $this->activityLogger->error('cancel', 'order', $id ?? null, $exception->getMessage());
            return $this->jsonResponse(['error' => $exception->getMessage()], 500);
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
