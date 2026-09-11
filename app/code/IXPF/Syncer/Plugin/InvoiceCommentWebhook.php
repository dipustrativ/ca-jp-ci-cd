<?php
declare(strict_types=1);

namespace IXPF\Syncer\Plugin;

/**
 * Invoice comment save fires the invoice webhook.
 */
class InvoiceCommentWebhook extends AbstractCommentWebhook
{
    /**
     * @inheritDoc
     */
    protected function getType(): string
    {
        return 'invoice';
    }
}
