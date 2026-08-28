<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\CreditMemo;

use Exception;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\CreditmemoCommentSender;

class AddComment
{
    public function __construct(
        protected readonly CreditmemoRepositoryInterface $creditmemoRepository,
        protected readonly CreditmemoCommentSender       $creditmemoCommentSender,
    ) {
    }

    /**
     * Add a comment to a credit memo.
     *
     * @param int   $creditmemoId
     * @param array $payload
     * @return array
     * @throws Exception
     */
    public function execute(int $creditmemoId, array $payload): array
    {
        try {
            $creditmemo = $this->creditmemoRepository->get($creditmemoId);
        } catch (NoSuchEntityException) {
            throw new Exception("Credit memo #$creditmemoId not found");
        }

        $comment = trim($payload['comment'] ?? '');
        if (empty($comment)) {
            throw new Exception("'comment' is required.");
        }

        $notify = !empty($payload['notify']);
        $visibleOnFront = !empty($payload['visible_storefront']);

        $creditmemo->addComment($comment, $notify, $visibleOnFront);
        $this->creditmemoRepository->save($creditmemo);

        if ($notify) {
            $this->creditmemoCommentSender->send($creditmemo, $notify, $comment);
        }

        return [
            'success'        => true,
            'creditmemo_id'  => (int) $creditmemo->getEntityId(),
            'increment_id'   => (string) $creditmemo->getIncrementId(),
            'comment'        => $comment,
            'notify'         => $notify,
            'visible_front'  => $visibleOnFront,
        ];
    }
}
