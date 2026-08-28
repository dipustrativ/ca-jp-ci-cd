<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Invoice;

use Exception;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\InvoiceCommentSender;

class AddComment
{
    public function __construct(
        protected readonly InvoiceRepositoryInterface $invoiceRepository,
        protected readonly InvoiceCommentSender       $invoiceCommentSender,
    ) {
    }

    /**
     * Add a comment to an invoice.
     *
     * @param int   $invoiceId
     * @param array $payload
     * @return array
     * @throws Exception
     */
    public function execute(int $invoiceId, array $payload): array
    {
        try {
            $invoice = $this->invoiceRepository->get($invoiceId);
        } catch (NoSuchEntityException) {
            throw new Exception("Invoice #$invoiceId not found");
        }

        $comment = trim($payload['comment'] ?? '');
        if (empty($comment)) {
            throw new Exception("'comment' is required.");
        }

        $notify = !empty($payload['notify']);
        $visibleOnFront = !empty($payload['visible_storefront']);

        $invoice->addComment($comment, $notify, $visibleOnFront);
        $this->invoiceRepository->save($invoice);

        if ($notify) {
            $this->invoiceCommentSender->send($invoice, $notify, $comment);
        }

        return [
            'success'        => true,
            'invoice_id'     => (int) $invoice->getEntityId(),
            'increment_id'   => (string) $invoice->getIncrementId(),
            'comment'        => $comment,
            'notify'         => $notify,
            'visible_front'  => $visibleOnFront,
        ];
    }
}
