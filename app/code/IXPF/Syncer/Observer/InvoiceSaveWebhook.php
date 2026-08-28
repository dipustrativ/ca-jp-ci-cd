<?php
declare(strict_types=1);

namespace IXPF\Syncer\Observer;

use Magento\Framework\Event\Observer;
use Magento\Sales\Api\Data\InvoiceInterface;

/**
 * Fire the invoice webhook on invoice save.
 */
class InvoiceSaveWebhook extends AbstractOrderEntityWebhook
{
    protected function getType(): string
    {
        return 'invoice';
    }

    protected function resolveObjectId(Observer $observer): ?int
    {
        /** @var InvoiceInterface|null $invoice */
        $invoice = $observer->getEvent()->getData('invoice');

        return $invoice && $invoice->getId() ? (int)$invoice->getId() : null;
    }
}
