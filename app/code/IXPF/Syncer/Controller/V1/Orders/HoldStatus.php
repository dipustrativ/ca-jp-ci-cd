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
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Hold / unhold an order in a single endpoint.
 *
 * action: "hold" | "unhold" (query param or JSON body).
 * Omitted → auto-toggle based on current state.
 */
class HoldStatus extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected readonly Config $config,
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly OrderManagementInterface $orderManagement,
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

            try {
                $order = $this->orderRepository->get((int) $id);
            } catch (NoSuchEntityException) {
                throw new Exception("Order #$id not found");
            }

            if (!in_array($order->getState(), ['new', 'processing', 'holded'])) {
                throw new Exception(
                    "Order #{$order->getIncrementId()} can only be hold and unhold in the new and processing state"
                    . "Current state: {$order->getState()}, status: {$order->getStatus()}"
                );
            }

            $action = $this->resolveAction($order);

            $this->orderLogger->writeDaily("[hole/unhold] Received $action request for Order ID: $id");

            if ($action === 'hold') {
                if (!$order->canHold()) {
                    throw new Exception(
                        "Order #{$order->getIncrementId()} cannot be held. "
                        . "State: {$order->getState()}, status: {$order->getStatus()}"
                    );
                }
                $this->orderManagement->hold((int) $id);
            } else {
                if (!$order->canUnhold()) {
                    throw new Exception(
                        "Order #{$order->getIncrementId()} is not on hold. "
                        . "State: {$order->getState()}, status: {$order->getStatus()}"
                    );
                }
                $this->orderManagement->unHold((int) $id);
            }

            $order = $this->orderRepository->get((int) $id);
            $verb = $action === 'hold' ? 'held' : 'unheld';
            $this->orderLogger->writeDaily("[hole/unhold] Order #{$order->getIncrementId()} $verb — status: {$order->getStatus()}");

            $this->activityLogger->success(
                $action,
                'order',
                $order->getIncrementId(),
                "Order #{$order->getIncrementId()} $verb — status: {$order->getStatus()}"
            );

            return $this->jsonResponse([
                'success' => true,
                'message' => "Order #{$order->getIncrementId()} $verb",
                'data'    => [
                    'order_id'     => (int) $order->getEntityId(),
                    'increment_id' => $order->getIncrementId(),
                    'action'       => $action,
                    'state'        => $order->getState(),
                    'status'       => $order->getStatus(),
                ],
            ]);
        } catch (Exception $exception) {
            $this->orderLogger->writeDaily("[hole/unhold] Error changing hold status: " . $exception->getMessage(), 'error');
            $this->activityLogger->error($action ?? 'hold_status', 'order', $id ?? null, $exception->getMessage());
            return $this->jsonResponse(['error' => $exception->getMessage()], 500);
        }
    }

    /**
     * Resolve requested action. Falls back to auto-toggle from current state.
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return string  "hold" | "unhold"
     * @throws Exception
     */
    private function resolveAction($order): string
    {
        $payload = json_decode($this->getRequest()->getContent() ?: '', true) ?: [];
        $action = $this->getRequest()->getParam('action') ?? ($payload['action'] ?? null);

        if ($action !== null) {
            $action = strtolower(trim((string) $action));
            if (!in_array($action, ['hold', 'unhold'], true)) {
                throw new Exception("Invalid action '$action'. Allowed: hold, unhold");
            }
            return $action;
        }

        // Auto-toggle
        return $order->canUnhold() ? 'unhold' : 'hold';
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
