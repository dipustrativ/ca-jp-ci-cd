<?php
declare(strict_types=1);

namespace IXPF\Syncer\Plugin;

use IXPF\Syncer\Helper\Logger\WebhookLogger;
use IXPF\Syncer\Helper\Webhook;

/**
 * Creditmemo comment-save webhook binding. See {@see CommentWebhook}.
 */
class CreditmemoCommentWebhook extends CommentWebhook
{
    public function __construct(Webhook $webhook, WebhookLogger $logger)
    {
        parent::__construct($webhook, $logger, 'creditmemo');
    }
}
