<?php
declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Shipments;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ActivityLogger;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\OrderActionLogger;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Services\Shipment\DeleteTrack as DeleteTrackService;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Deletes tracking number(s) from an existing shipment.
 *
 * POST /ixpfsyncer/V1/Shipments/DeleteTrack?id=<shipment_id>
 */
class DeleteTrack extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context                               $context,
        TokenValidator                        $tokenValidator,
        JsonFactory                           $resultFactory,
        protected readonly Config             $config,
        protected readonly DeleteTrackService $deleteTrack,
        protected readonly OrderActionLogger  $orderLogger,
        protected readonly ActivityLogger     $activityLogger,
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        $shipmentId = 0;
        try {
            $this->tokenValidator();

            if (!$this->config->isShipmentUpdateEnabled()) {
                throw new Exception('Shipment is disabled configuration');
            }

            $raw = (string) $this->getRequest()->getContent();
            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                throw new InputException(__('Invalid or empty JSON body'));
            }

            $shipmentId = (int) ($this->getRequest()->getParam('id')
                ?: $this->getRequest()->getParam('shipment_id')
                ?: ($payload['shipment_id'] ?? 0));
            if ($shipmentId <= 0) {
                throw new InputException(__('shipment_id is required'));
            }

            $this->orderLogger->writeDaily("[shipment_delete_track] Received delete track request for Shipment ID: $shipmentId — payload: $raw");

            $result = $this->deleteTrack->execute($shipmentId, $payload);

            $this->orderLogger->writeDaily('[shipment_delete_track] Result: ' . json_encode($result));

            $deleted = array_column($result['deleted'], 'track_number');
            $notFound = array_map(
                fn(array $row) => $row['track_number'] . ' (' . $row['carrier_code'] . ')',
                $result['not_found']
            );
            $message = 'Tracks deleted: ' . ($deleted ? implode(', ', $deleted) : 'none')
                . ($notFound ? '; not found: ' . implode(', ', $notFound) : '');

            if (!$result['success']) {
                // Nothing matched — report as not found instead of a misleading success
                $result['error'] = 'No matching track found on shipment #' . $result['shipment_increment_id']
                    . ' — track_number and carrier_code must both match';
                $this->activityLogger->error('shipment_delete_track', 'shipment', $result['shipment_increment_id'], $message);
                return $this->jsonResponse($result, 404);
            }

            $this->activityLogger->success('shipment_delete_track', 'shipment', $result['shipment_increment_id'], $message);

            return $this->jsonResponse($result);
        } catch (Exception $e) {
            $status = match (true) {
                in_array($e->getMessage(), ['Unauthorized', 'Unauthorized token'], true) => 401,
                $e instanceof NoSuchEntityException => 404,
                $e instanceof InputException        => 422,
                default                             => 500,
            };
            $this->orderLogger->writeDaily('[shipment_delete_track] Error deleting track: ' . $e->getMessage(), 'error');
            $this->activityLogger->error('shipment_delete_track', 'shipment', $shipmentId ?: null, $e->getMessage());
            return $this->jsonResponse(['error' => $e->getMessage()], $status);
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
