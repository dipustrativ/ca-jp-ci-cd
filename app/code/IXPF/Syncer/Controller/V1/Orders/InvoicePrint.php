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
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Model\Order\Pdf\Invoice as InvoicePdf;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;

/**
 * Returns the invoice PDF — same document as the admin "Print" button on an invoice.
 * Accepts invoice_id (single invoice) or order_id (all invoices of the order, combined).
 */
class InvoicePrint extends AbstractController implements
    HttpGetActionInterface,
    HttpPostActionInterface,
    CsrfAwareActionInterface
{
    public function __construct(
        Context                                       $context,
        TokenValidator                                $tokenValidator,
        JsonFactory                                   $resultFactory,
        protected readonly Config                    $config,
        protected readonly InvoiceRepositoryInterface $invoiceRepository,
        protected readonly InvoiceCollectionFactory   $invoiceCollectionFactory,
        protected readonly InvoicePdf                 $invoicePdf,
        protected readonly OrderActionLogger          $orderLogger,
        protected readonly ActivityLogger             $activityLogger,
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            $this->tokenValidator();

            // accept params from query string or JSON body
            $invoiceId = (int) $this->getRequest()->getParam('invoice_id');
            $orderId   = (int) $this->getRequest()->getParam('order_id');

            if (!$invoiceId && !$orderId) {
                $payload = json_decode((string) $this->getRequest()->getContent(), true) ?: [];
                $invoiceId = (int) ($payload['invoice_id'] ?? 0);
                $orderId   = (int) ($payload['order_id'] ?? 0);
            }

            if (!$invoiceId && !$orderId) {
                throw new Exception('invoice_id or order_id is required');
            }

            if (!$this->config->isInvoiceUpdateEnabled()) {
                throw new Exception('Invoice is disabled in configuration');
            }

            $this->orderLogger->writeDaily(
                '[invoice_print] Received print request — '
                . ($invoiceId ? "Invoice ID: $invoiceId" : "Order ID: $orderId")
            );

            [$invoices, $fileName] = $invoiceId
                ? $this->loadByInvoice($invoiceId)
                : $this->loadByOrder($orderId);

            $pdf = $this->invoicePdf->getPdf($invoices);
            $pdfContent = $pdf->render();

            $this->orderLogger->writeDaily(
                "[invoice_print] PDF generated — file: $fileName, count: " . count($invoices)
            );

            $this->activityLogger->success(
                'invoice_print',
                'invoice',
                $invoiceId ?: $orderId,
                "PDF generated — file: $fileName, count: " . count($invoices)
            );

            return $this->jsonResponse([
                'success'      => true,
                'invoice_id'   => $invoiceId ?: null,
                'order_id'     => $orderId ?: null,
                'count'        => count($invoices),
                'file_name'    => $fileName,
                'file_content' => base64_encode($pdfContent),
                'mime_type'    => 'application/pdf',
            ]);
        } catch (Exception $exception) {
            $this->orderLogger->writeDaily('[invoice_print] Error printing invoice: ' . $exception->getMessage(), 'error');
            $this->activityLogger->error(
                'invoice_print',
                'invoice',
                ($invoiceId ?? null) ?: ($orderId ?? null),
                $exception->getMessage()
            );
            return $this->jsonResponse([
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * @return array{0: array, 1: string}
     */
    private function loadByInvoice(int $invoiceId): array
    {
        $invoice = $this->invoiceRepository->get($invoiceId);
        return [[$invoice], "invoice_{$invoice->getIncrementId()}.pdf"];
    }

    /**
     * @return array{0: array, 1: string}
     */
    private function loadByOrder(int $orderId): array
    {
        $collection = $this->invoiceCollectionFactory->create()
            ->addFieldToFilter('order_id', $orderId)
            ->setOrder('entity_id', 'ASC');

        $invoices = array_values($collection->getItems());

        if (!$invoices) {
            throw new Exception("No invoices found for order #$orderId");
        }

        return [$invoices, "invoices_order_{$orderId}.pdf"];
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
