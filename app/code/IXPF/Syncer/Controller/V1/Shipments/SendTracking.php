<?php
declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Shipments;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ActivityLogger;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\OrderActionLogger;
use IXPF\Syncer\Helper\TokenValidator;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Shipping\Model\ShipmentNotifier;

/**
 * Sends the shipment email with tracking information to the customer — same
 * action as the admin "Send Tracking Information" button on a shipment.
 */
class SendTracking extends AbstractController implements
    HttpPostActionInterface,
    CsrfAwareActionInterface
{
    public function __construct(
        Context                                        $context,
        TokenValidator                                 $tokenValidator,
        JsonFactory                                    $resultFactory,
        protected readonly Config $config,
        protected readonly ShipmentRepositoryInterface $shipmentRepository,
        protected readonly ShipmentNotifier            $shipmentNotifier,
        protected readonly OrderActionLogger           $orderLogger,
        protected readonly ActivityLogger              $activityLogger,
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            $this->tokenValidator();

            $shipmentId = (int) ($this->getRequest()->getParam('id') ?: $this->getRequest()->getParam('shipment_id'));
            if (!$shipmentId) {
                $payload = json_decode((string) $this->getRequest()->getContent(), true) ?: [];
                $shipmentId = (int) ($payload['shipment_id'] ?? 0);
            }

            if (!$shipmentId) {
                throw new Exception('shipment_id is required');
            }
            if (!$this->config->isShipmentUpdateEnabled()) {
                throw new Exception("Shipment is disabled configuration");
            }

            $this->orderLogger->writeDaily("[send_tracking] Received send tracking request for Shipment ID: $shipmentId");

            $shipment = $this->shipmentRepository->get($shipmentId);

            $sent = $this->shipmentNotifier->notify($shipment);
            if (!$sent) {
                throw new Exception("Could not send tracking information for shipment #{$shipment->getIncrementId()}");
            }

            $trackNumbers = [];
            foreach ($shipment->getTracks() as $track) {
                $trackNumbers[] = $track->getTrackNumber();
            }

            $this->orderLogger->writeDaily(
                "[send_tracking] Tracking information sent for Shipment #{$shipment->getIncrementId()}"
                . " — Order ID: {$shipment->getOrderId()}, tracks: "
                . ($trackNumbers ? implode(', ', $trackNumbers) : 'none')
            );

            $this->activityLogger->success(
                'send_tracking',
                'shipment',
                $shipment->getIncrementId(),
                "Tracking information sent — Order ID: {$shipment->getOrderId()}, tracks: "
                . ($trackNumbers ? implode(', ', $trackNumbers) : 'none')
            );

            return $this->jsonResponse([
                'success'               => true,
                'shipment_id'           => (int) $shipment->getEntityId(),
                'shipment_increment_id' => $shipment->getIncrementId(),
                'order_id'              => (int) $shipment->getOrderId(),
                'track_numbers'         => $trackNumbers,
                'message'               => "Tracking information sent for shipment #{$shipment->getIncrementId()}",
            ]);
        } catch (Exception $exception) {
            $this->orderLogger->writeDaily('[send_tracking] Error sending tracking information: ' . $exception->getMessage(), 'error');
            $this->activityLogger->error('send_tracking', 'shipment', $shipmentId ?? null, $exception->getMessage());
            return $this->jsonResponse([
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
