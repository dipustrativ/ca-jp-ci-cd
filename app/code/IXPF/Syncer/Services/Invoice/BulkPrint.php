<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Invoice;

use Exception;
use Magento\Framework\App\RequestInterface;
use Magento\Sales\Model\Order\Pdf\Invoice as InvoicePdf;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;

/**
 * Render a set of invoices into one combined PDF — mirrors the admin invoice
 * grid mass actions "PDF Invoices" and "PDF Canceled Invoices".
 *
 * The cancellation variant is driven by the `canceled` request param: the
 * project's PDF renderer (Evalent\InvoicePaymentMods override of
 * Magento\Sales\Model\Order\Pdf\Invoice) reads it to switch to the
 * "Cancellation invoice" layout and to reject any selected invoice that is not
 * actually canceled — same behaviour as the admin url `.../pdfinvoices/canceled/1`.
 */
class BulkPrint
{
    public function __construct(
        protected readonly InvoiceCollectionFactory $invoiceCollectionFactory,
        protected readonly InvoicePdf $invoicePdf,
        protected readonly RequestInterface $request,
    ) {
    }

    /**
     * @param int[] $invoiceIds
     * @param bool  $canceledOnly  render the cancellation-invoice variant; every
     *                             selected invoice must be canceled or it throws.
     * @return array
     * @throws Exception
     */
    public function execute(array $invoiceIds, bool $canceledOnly = false): array
    {
        $collection = $this->invoiceCollectionFactory->create()
            ->addFieldToFilter('entity_id', ['in' => $invoiceIds])
            ->setOrder('entity_id', 'ASC');

        $docs = array_values($collection->getItems());
        if (!$docs) {
            throw new Exception('No invoices found for the selected ids');
        }

        $pdfContent = $this->renderWithCanceledFlag($docs, $canceledOnly);

        $coveredIds = array_values(array_unique(array_map(
            static fn ($doc) => (int) $doc->getId(),
            $docs
        )));
        $missing = array_values(array_diff($invoiceIds, $coveredIds));

        return [
            'count'        => count($docs),
            'missing'      => $missing,
            'file_name'    => $canceledOnly ? 'canceled_invoices_bulk.pdf' : 'invoices_bulk.pdf',
            'file_content' => base64_encode($pdfContent),
            'mime_type'    => 'application/pdf',
        ];
    }

    /**
     * Toggle the shared `canceled` request param around rendering and restore it
     * afterwards, so the renderer picks the right layout without leaking state.
     *
     * @param object[] $docs
     * @throws Exception
     */
    private function renderWithCanceledFlag(array $docs, bool $canceledOnly): string
    {
        $params = $this->request->getParams();
        $this->request->setParams(array_merge($params, ['canceled' => $canceledOnly ? 1 : 0]));
        try {
            return $this->invoicePdf->getPdf($docs)->render();
        } finally {
            $this->request->setParams($params);
        }
    }
}
