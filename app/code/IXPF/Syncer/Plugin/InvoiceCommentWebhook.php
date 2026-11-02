<?php
declare(strict_types=1);

namespace IXPF\Syncer\Plugin;

use IXPF\Syncer\Helper\Logger\WebhookLogger;
use IXPF\Syncer\Helper\Webhook;

/**
 * Invoice comment-save webhook binding. See {@see CommentWebhook}.
 */
class InvoiceCommentWebhook extends CommentWebhook
{
    public function __construct(Webhook $webhook, WebhookLogger $logger)
    {
        parent::__construct($webhook, $logger, 'invoice');
    }
}
