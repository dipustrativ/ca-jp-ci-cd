<?php

namespace IXPF\Syncer\Controller\V1\Orders;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ActivityLogger;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\OrderActionLogger;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Services\Order\OrderUpdate;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class Comment extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected readonly Config $config,
        protected readonly OrderUpdate $orderUpdate,
        protected readonly OrderActionLogger $orderLogger,
        protected readonly ActivityLogger $activityLogger
    )
    {
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

            $this->orderLogger->writeDaily("[comment] Received add comment request for Order ID: $id");

            $body = json_decode($this->getRequest()->getContent(), true) ?? [];

            $payload = [
                'status' => $body['status'] ?? '',
                'comment' => $body['comment'] ?? '',
                'visible_storefront' => $body['visible_storefront'] ?? false,
                'notify_customer' => $body['notify_customer'] ?? false,
            ];
            $this->orderLogger->writeDaily('[comment] Payload: ' . json_encode($payload));

            $result = $this->orderUpdate->addComment((int)$id, $payload);

            $this->orderLogger->writeDaily(
                "[comment] Comment added for Order ID: $id — " . json_encode($result)
            );

            $this->activityLogger->success('comment', 'order', $id, "Comment added for Order ID: $id");

            return $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->orderLogger->writeDaily('[comment] Error adding order comment: ' . $e->getMessage(), 'error');
            $this->activityLogger->error('comment', 'order', $id ?? null, $e->getMessage());
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
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