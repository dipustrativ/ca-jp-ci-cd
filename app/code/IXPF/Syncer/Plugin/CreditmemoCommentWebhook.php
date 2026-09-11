<?php
declare(strict_types=1);

namespace IXPF\Syncer\Plugin;

/**
 * Credit memo comment save fires the creditmemo webhook.
 */
class CreditmemoCommentWebhook extends AbstractCommentWebhook
{
    /**
     * @inheritDoc
     */
    protected function getType(): string
    {
        return 'creditmemo';
    }
}
