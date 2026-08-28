<?php

namespace IXPF\Syncer\Controller\V1\Shipments;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ActivityLogger;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\OrderActionLogger;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Services\Shipment\AddComment;
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
        protected readonly AddComment $addComment,
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

            $id = $this->getRequest()->getParam('id') ?: $this->getRequest()->getParam('shipment_id');
            if (!$id) {
                throw new Exception('Shipment ID is required');
            }

            if (!$this->config->isShipmentUpdateEnabled()) {
                throw new Exception("Shipment is disabled configuration");
            }

            $this->orderLogger->writeDaily("[shipment_comment] Received add comment request for Shipment ID: $id");

            $body = json_decode($this->getRequest()->getContent(), true) ?? [];

            $payload = [
                'comment' => $body['comment'] ?? '',
                'notify' => $body['notify'] ?? false,
                'visible_storefront' => $body['visible_storefront'] ?? false,
            ];
            $this->orderLogger->writeDaily('[shipment_comment] Payload: ' . json_encode($payload));

            $result = $this->addComment->execute((int)$id, $payload);

            $this->orderLogger->writeDaily(
                "[shipment_comment] Comment added for Shipment ID: $id — " . json_encode($result)
            );

            $this->activityLogger->success('shipment_comment', 'shipment', $id, "Comment added for Shipment ID: $id");

            return $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->orderLogger->writeDaily('[shipment_comment] Error adding shipment comment: ' . $e->getMessage(), 'error');
            $this->activityLogger->error('shipment_comment', 'shipment', $id ?? null, $e->getMessage());
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
