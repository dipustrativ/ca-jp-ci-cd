<?php
declare(strict_types=1);

namespace IXPF\Syncer\Plugin;

use IXPF\Syncer\Helper\Logger\WebhookLogger;
use IXPF\Syncer\Helper\Webhook;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Throwable;

/**
 * Fire the parent-entity webhook when a sales comment is saved.
 *
 * Adding a comment (order status history / invoice / shipment / creditmemo
 * comment) does NOT modify the parent row, so the VersionControl snapshot marks
 * the parent unmodified and its *_save_after event never fires — the comment
 * change is invisible to the save-based observers. This plugin hooks the comment
 * resource save (the single choke point for both model->save() and the parent's
 * relation-composite cascade) and fires the webhook for the parent, using the
 * parent id carried on the comment (getParentId).
 *
 * Only newly inserted comments fire. Adding one comment makes the parent
 * re-save ALL its loaded comments through this resource (relation cascade);
 * those existing rows are unchanged and must not each send a webhook.
 *
 * The comment is usually saved inside the parent's transaction, which still
 * holds the parent row lock. The send is deferred to a commit callback so IXPF
 * cannot call back while the lock is held (deadlock, SQLSTATE 40001) or read
 * uncommitted data. A rolled-back save sends nothing.
 *
 * The parent $type ("order" / "invoice" / "shipment" / "creditmemo") is injected
 * per binding via di.xml virtualTypes.
 */
class CommentWebhook
{
    public function __construct(
        private readonly Webhook $webhook,
        private readonly WebhookLogger $logger,
        private readonly string $type
    ) {
    }

    /**
     * Comments without an id at this point are new inserts, keyed by object id.
     *
     * @var array<int, true>
     */
    private array $newComments = [];

    /**
     * @param AbstractDb    $subject Comment resource model.
     * @param AbstractModel $comment The comment about to be saved.
     */
    public function beforeSave(AbstractDb $subject, AbstractModel $comment): void
    {
        if (!$comment->getId()) {
            $this->newComments[spl_object_id($comment)] = true;
        }
    }

    /**
     * @param AbstractDb    $subject Comment resource model.
     * @param AbstractDb    $result  Return of save() (the resource itself).
     * @param AbstractModel $comment The saved comment carrying the parent id.
     */
    public function afterSave(AbstractDb $subject, $result, AbstractModel $comment)
    {
        $key = spl_object_id($comment);
        if (!isset($this->newComments[$key])) {
            return $result;
        }
        unset($this->newComments[$key]);

        $parentId = (int)$comment->getData('parent_id');
        if (!$parentId) {
            return $result;
        }

        try {
            if ($subject->getConnection()->getTransactionLevel() > 0) {
                $subject->addCommitCallback(fn() => $this->send($parentId));
            } else {
                $this->send($parentId);
            }
        } catch (Throwable $e) {
            $this->logger->writeExceptionDaily($e, static::class . ' webhook schedule exception');
        }

        return $result;
    }

    private function send(int $parentId): void
    {
        try {
            $this->webhook->sendNow($this->type, $parentId);
        } catch (Throwable $e) {
            // Never break the comment save because of webhook send.
            $this->logger->writeExceptionDaily($e, static::class . ' webhook send exception');
        }
    }
}
