<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Shipment;

use Exception;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\ShipmentCommentSender;

class AddComment
{
    public function __construct(
        protected readonly ShipmentRepositoryInterface $shipmentRepository,
        protected readonly ShipmentCommentSender       $shipmentCommentSender,
    ) {
    }

    /**
     * Add a comment to a shipment.
     *
     * @param int   $shipmentId
     * @param array $payload
     * @return array
     * @throws Exception
     */
    public function execute(int $shipmentId, array $payload): array
    {
        try {
            $shipment = $this->shipmentRepository->get($shipmentId);
        } catch (NoSuchEntityException) {
            throw new Exception("Shipment #$shipmentId not found");
        }

        $comment = trim($payload['comment'] ?? '');
        if (empty($comment)) {
            throw new Exception("'comment' is required.");
        }

        $notify = !empty($payload['notify']);
        $visibleOnFront = !empty($payload['visible_storefront']);

        $shipment->addComment($comment, $notify, $visibleOnFront);
        $this->shipmentRepository->save($shipment);

        if ($notify) {
            $this->shipmentCommentSender->send($shipment, $notify, $comment);
        }

        return [
            'success'        => true,
            'shipment_id'    => (int) $shipment->getEntityId(),
            'increment_id'   => (string) $shipment->getIncrementId(),
            'comment'        => $comment,
            'notify'         => $notify,
            'visible_front'  => $visibleOnFront,
        ];
    }
}
