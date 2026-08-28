<?php
declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Invoice;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ActivityLogger;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\OrderActionLogger;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Services\Invoice\BulkPrint;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Single endpoint for bulk invoice actions over selected invoice ids.
 *
 * POST /ixpfsyncer/V1/Invoice/BulkAction
 *
 * Body:
 *   {
 *     "action": "pdf_invoices | pdf_canceled_invoices",
 *     "invoice_ids": [1, 2, 3]
 *   }
 *
 * Mirrors the admin invoice grid mass actions:
 *   - pdf_invoices           → "PDF Invoices"
 *   - pdf_canceled_invoices  → "PDF Canceled Invoices" (every selected invoice
 *                              must be canceled, else it errors)
 *
 * Returns one combined PDF (base64) across all selected invoices.
 */
class BulkAction extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const ACTION_PDF_INVOICES          = 'pdf_invoices';
    private const ACTION_PDF_CANCELED_INVOICES = 'pdf_canceled_invoices';

    private const ACTIONS = [
        self::ACTION_PDF_INVOICES,
        self::ACTION_PDF_CANCELED_INVOICES,
    ];

    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected readonly Config $config,
        protected readonly BulkPrint $bulkPrint,
        protected readonly OrderActionLogger $orderLogger,
        protected readonly ActivityLogger $activityLogger,
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        $action = null;
        try {
            $this->tokenValidator();

            if (!$this->config->isInvoiceUpdateEnabled()) {
                throw new Exception('Invoice action is disabled in configuration');
            }

            $payload    = json_decode((string) $this->getRequest()->getContent(), true) ?: [];
            $action     = strtolower(trim((string) ($this->getRequest()->getParam('action') ?? $payload['action'] ?? '')));
            $invoiceIds = $this->resolveInvoiceIds($payload);

            if ($action === '') {
                throw new Exception('action is required');
            }
            if (!in_array($action, self::ACTIONS, true)) {
                throw new Exception(
                    "Invalid action '$action'. Allowed: " . implode(', ', self::ACTIONS)
                );
            }
            if (!$invoiceIds) {
                throw new Exception('invoice_ids is required (non-empty array of invoice IDs)');
            }

            $this->orderLogger->writeDaily(
                "[invoice_bulk_action] action=$action invoice_ids=" . implode(',', $invoiceIds)
            );

            $canceledOnly = $action === self::ACTION_PDF_CANCELED_INVOICES;
            $data = $this->bulkPrint->execute($invoiceIds, $canceledOnly);

            $message = "[$action] file: {$data['file_name']}, docs: " . ($data['count'] ?? 0)
                . (!empty($data['missing']) ? ', missing: ' . implode(',', $data['missing']) : '');
            $this->orderLogger->writeDaily("[invoice_bulk_action] $message");
            $this->activityLogger->success("invoice_bulk_action_$action", 'invoice', implode(',', $invoiceIds), $message);

            return $this->jsonResponse(['success' => true, 'action' => $action] + $data);
        } catch (Exception $exception) {
            $this->orderLogger->writeDaily('[invoice_bulk_action] Error: ' . $exception->getMessage(), 'error');
            $activityType = $action ? "invoice_bulk_action_$action" : 'invoice_bulk_action';
            $this->activityLogger->error($activityType, 'invoice', null, $exception->getMessage());
            return $this->jsonResponse(['success' => false, 'error' => $exception->getMessage()], 400);
        }
    }

    /**
     * Normalise + de-duplicate invoice IDs from query param or JSON body.
     *
     * @return int[]
     */
    private function resolveInvoiceIds(array $payload): array
    {
        $raw = $this->getRequest()->getParam('invoice_ids') ?? $payload['invoice_ids'] ?? [];
        if (is_string($raw)) {
            $raw = array_filter(array_map('trim', explode(',', $raw)), 'strlen');
        }
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $raw))));
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
