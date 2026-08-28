<?php
declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Invoice;

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
use Magento\Sales\Api\InvoiceManagementInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;

/**
 * Sends the invoice receipt email to the customer — same action as the admin
 * "Send Receipt" button (Evalent\InvoicePaymentMods).
 */
class SendReceipt extends AbstractController implements
    HttpPostActionInterface,
    CsrfAwareActionInterface
{
    public function __construct(
        Context                                        $context,
        TokenValidator                                 $tokenValidator,
        JsonFactory                                    $resultFactory,
        protected readonly Config                     $config,
        protected readonly InvoiceRepositoryInterface  $invoiceRepository,
        protected readonly InvoiceManagementInterface  $invoiceManagement,
        protected readonly OrderActionLogger            $orderLogger,
        protected readonly ActivityLogger               $activityLogger
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            $this->tokenValidator();

            $invoiceId = (int) $this->getRequest()->getParam('invoice_id');
            if (!$invoiceId) {
                $payload = json_decode((string) $this->getRequest()->getContent(), true) ?: [];
                $invoiceId = (int) ($payload['invoice_id'] ?? 0);
            }

            if (!$invoiceId) {
                throw new Exception('invoice_id is required');
            }

            if (!$this->config->isInvoiceUpdateEnabled()) {
                throw new Exception('Invoice is disabled in configuration');
            }

            $this->orderLogger->writeDaily("[send_receipt] Received send receipt request for Invoice ID: $invoiceId");

            $invoice = $this->invoiceRepository->get($invoiceId);

            $sent = $this->invoiceManagement->notify($invoice->getEntityId());
            if (!$sent) {
                throw new Exception("Could not send receipt for invoice #{$invoice->getIncrementId()}");
            }

            $this->orderLogger->writeDaily(
                "[send_receipt] Receipt sent for invoice #{$invoice->getIncrementId()} — Order ID: {$invoice->getOrderId()}"
            );

            $this->activityLogger->success(
                'send_receipt',
                'invoice',
                $invoice->getIncrementId(),
                "Receipt sent — Order ID: {$invoice->getOrderId()}"
            );

            return $this->jsonResponse([
                'success'              => true,
                'invoice_id'           => (int) $invoice->getEntityId(),
                'invoice_increment_id' => $invoice->getIncrementId(),
                'order_id'             => (int) $invoice->getOrderId(),
                'message'              => "Receipt sent for invoice #{$invoice->getIncrementId()}",
            ]);
        } catch (Exception $exception) {
            $this->orderLogger->writeDaily('[send_receipt] Error sending receipt: ' . $exception->getMessage(), 'error');
            $this->activityLogger->error('send_receipt', 'invoice', $invoiceId ?? null, $exception->getMessage());
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
