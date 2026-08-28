<?php

namespace IXPF\Syncer\Controller\V1\Invoice;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ActivityLogger;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\OrderActionLogger;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Services\Invoice\AddComment;
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

            $id = $this->getRequest()->getParam('id') ?: $this->getRequest()->getParam('invoice_id');
            if (!$id) {
                throw new Exception('Invoice ID is required');
            }

            if (!$this->config->isInvoiceUpdateEnabled()) {
                throw new Exception('Invoice is disabled in configuration');
            }

            $this->orderLogger->writeDaily("[invoice_comment] Received add comment request for Invoice ID: $id");

            $body = json_decode($this->getRequest()->getContent(), true) ?? [];

            $payload = [
                'comment' => $body['comment'] ?? '',
                'notify' => $body['notify'] ?? false,
                'visible_storefront' => $body['visible_storefront'] ?? false,
            ];
            $this->orderLogger->writeDaily('[invoice_comment] Payload: ' . json_encode($payload));

            $result = $this->addComment->execute((int)$id, $payload);

            $this->orderLogger->writeDaily(
                "[invoice_comment] Comment added for Invoice ID: $id — " . json_encode($result)
            );

            $this->activityLogger->success('invoice_comment', 'invoice', $id, "Comment added for Invoice ID: $id");

            return $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->orderLogger->writeDaily('[invoice_comment] Error adding invoice comment: ' . $e->getMessage(), 'error');
            $this->activityLogger->error('invoice_comment', 'invoice', $id ?? null, $e->getMessage());
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
