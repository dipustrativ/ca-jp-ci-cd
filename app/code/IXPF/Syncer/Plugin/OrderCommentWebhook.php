<?php
declare(strict_types=1);

namespace IXPF\Syncer\Plugin;

use IXPF\Syncer\Helper\Logger\WebhookLogger;
use IXPF\Syncer\Helper\Webhook;

/**
 * Order comment-save webhook binding. See {@see CommentWebhook}.
 */
class OrderCommentWebhook extends CommentWebhook
{
    public function __construct(Webhook $webhook, WebhookLogger $logger)
    {
        parent::__construct($webhook, $logger, 'order');
    }
}
