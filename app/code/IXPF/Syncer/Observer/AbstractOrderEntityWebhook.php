<?php
declare(strict_types=1);

namespace IXPF\Syncer\Observer;

use IXPF\Syncer\Helper\Logger\WebhookLogger;
use IXPF\Syncer\Helper\Webhook;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Throwable;

/**
 * Base observer: fire a webhook instantly on a sales entity save.
 *
 * Bound to *_save_commit_after events, so the webhook leaves only once the
 * transaction has committed and released its row locks.
 *
 * Each entity carries its own type (order / invoice / shipment / creditmemo)
 * and its own id. The send is fire-and-forget, so the save request is never
 * blocked by the endpoint.
 */
abstract class AbstractOrderEntityWebhook implements ObserverInterface
{
    public function __construct(
        protected readonly Webhook $webhook,
        protected readonly WebhookLogger $logger
    ) {
    }

    /**
     * Webhook type for this entity, e.g. "invoice".
     */
    abstract protected function getType(): string;

    /**
     * Resolve the saved entity id, or null to skip.
     */
    abstract protected function resolveObjectId(Observer $observer): ?int;

    public function execute(Observer $observer): void
    {
        try {
            $objectId = $this->resolveObjectId($observer);
            if (!$objectId) {
                return;
            }

            $this->webhook->sendNow($this->getType(), $objectId);
        } catch (Throwable $e) {
            // Never break the save because of webhook send.
            $this->logger->writeExceptionDaily($e, static::class . ' webhook send exception');
        }
    }
}
