<?php
declare(strict_types=1);

namespace IXPF\Syncer\Helper;

use Exception;
use IXPF\Syncer\Model\IxpfOrderApiActivity;
use IXPF\Syncer\Model\IxpfOrderApiActivityFactory;
use IXPF\Syncer\Model\ResourceModel\IxpfOrderApiActivity as IxpfOrderApiActivityResource;
use Psr\Log\LoggerInterface;

/**
 * Records order API activity into the ixpf_order_api_activity table.
 *
 * Usage:
 *   $this->activityLogger->add('comment', 'order', $orderId, 'Comment added');
 *   $this->activityLogger->success('send_tracking', 'shipment', $shipmentId);
 *   $this->activityLogger->error('invoice_print', 'invoice', $invoiceId, $e->getMessage());
 */
class ActivityLogger
{
    public function __construct(
        protected readonly IxpfOrderApiActivityFactory  $activityFactory,
        protected readonly IxpfOrderApiActivityResource $activityResource,
        protected readonly LoggerInterface              $logger,
    ) {
    }

    /**
     * Record an activity. Never throws — activity logging must not break the API call.
     *
     * @param string          $activityType Action performed, e.g. comment, send_tracking, invoice_print
     * @param string          $resource     Resource acted on, e.g. order, shipment, invoice, creditmemo
     * @param int|string|null $referenceId  Resource ID or increment ID
     * @param string|null     $message      Detail message or payload summary
     * @param string          $status       success / error
     */
    public function add(
        string $activityType,
        string $resource,
        int|string|null $referenceId = null,
        ?string $message = null,
        string $status = IxpfOrderApiActivity::STATUS_SUCCESS
    ): void {
        try {
            $activity = $this->activityFactory->create();
            $activity->setData([
                'activity_type' => $activityType,
                'resource'      => $resource,
                'reference_id'  => $referenceId !== null ? (string) $referenceId : null,
                'status'        => $status,
                'message'       => $message,
            ]);
            $this->activityResource->save($activity);
        } catch (Exception $exception) {
            $this->logger->error('[IXPF ActivityLogger] Failed to save activity: ' . $exception->getMessage());
        }
    }

    public function success(
        string $activityType,
        string $resource,
        int|string|null $referenceId = null,
        ?string $message = null
    ): void {
        $this->add($activityType, $resource, $referenceId, $message, IxpfOrderApiActivity::STATUS_SUCCESS);
    }

    public function error(
        string $activityType,
        string $resource,
        int|string|null $referenceId = null,
        ?string $message = null
    ): void {
        $this->add($activityType, $resource, $referenceId, $message, IxpfOrderApiActivity::STATUS_ERROR);
    }
}
