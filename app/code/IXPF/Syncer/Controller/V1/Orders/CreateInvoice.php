<?php
declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Orders;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ActivityLogger;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\OrderLogger;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Services\Order\CreateInvoice as CreateInvoiceService;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class CreateInvoice extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected readonly CreateInvoiceService $createInvoice,
        protected readonly Config $config,
        protected readonly OrderLogger $orderLogger,
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

            if (!$this->config->isInvoiceUpdateEnabled()) {
                throw new Exception('Invoice is disabled in configuration');
            }

            $requestData = $this->getRequest()->getContent();
            $payload = json_decode($requestData, true) ?? [];

            $this->orderLogger->writeDaily("[invoice] Received create invoice request for Order ID: $id");
            $this->orderLogger->writeDaily(json_encode($payload));

            $result = $this->createInvoice->execute((int) $id, $payload);

            $this->orderLogger->writeDaily("[invoice] Invoice created for Order ID: $id — Invoice: {$result['invoice_increment_id']}");

            $this->activityLogger->success(
                'create_invoice',
                'order',
                $id,
                "Invoice {$result['invoice_increment_id']} created for order #{$result['increment_id']}"
            );

            return $this->jsonResponse([
                'success' => true,
                'message' => "Invoice created for order #{$result['increment_id']}",
                'data'    => $result,
            ]);
        } catch (Exception $exception) {
            $this->orderLogger->writeDaily("[invoice] Error creating invoice: " . $exception->getMessage(), 'error');
            $this->activityLogger->error('create_invoice', 'order', $id ?? null, $exception->getMessage());
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
