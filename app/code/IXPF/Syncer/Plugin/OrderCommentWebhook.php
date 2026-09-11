<?php
declare(strict_types=1);

namespace IXPF\Syncer\Plugin;

/**
 * Order status history save fires the order webhook.
 */
class OrderCommentWebhook extends AbstractCommentWebhook
{
    /**
     * @inheritDoc
     */
    protected function getType(): string
    {
        return 'order';
    }
}
