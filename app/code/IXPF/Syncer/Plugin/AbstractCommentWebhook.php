<?php
declare(strict_types=1);

namespace IXPF\Syncer\Plugin;

use IXPF\Syncer\Helper\Logger\WebhookLogger;
use IXPF\Syncer\Helper\Webhook;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Throwable;

/**
 * Base plugin: fire the parent entity webhook when a comment row is saved.
 *
 * Adding a comment does not touch the parent row, so the parent's
 * *_save_after event never fires. Hook the comment resource save instead
 * and send the webhook for the comment's parent id.
 */
abstract class AbstractCommentWebhook
{
    /**
     * @param Webhook $webhook
     * @param WebhookLogger $logger
     */
    public function __construct(
        protected readonly Webhook $webhook,
        protected readonly WebhookLogger $logger
    ) {
    }

    /**
     * Webhook type of the parent entity, e.g. "invoice".
     */
    abstract protected function getType(): string;

    /**
     * Send the parent entity webhook after the comment row is persisted.
     *
     * @param AbstractDb $subject
     * @param AbstractDb $result
     * @param AbstractModel $object
     * @return AbstractDb
     */
    public function afterSave(AbstractDb $subject, $result, AbstractModel $object)
    {
        try {
            $parentId = (int)$object->getData('parent_id');
            if ($parentId) {
                $this->webhook->sendNow($this->getType(), $parentId);
            }
        } catch (Throwable $e) {
            // Never break the comment save because of webhook send.
            $this->logger->writeExceptionDaily($e, static::class . ' webhook send exception');
        }

        return $result;
    }
}
