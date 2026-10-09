<?php
declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Shipments;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ActivityLogger;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\OrderActionLogger;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Services\Shipment\AddTrack as AddTrackService;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Adds tracking number(s) to an existing shipment.
 *
 * POST /ixpfsyncer/V1/Shipments/AddTrack?id=<shipment_id>
 */
class AddTrack extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context                            $context,
        TokenValidator                     $tokenValidator,
        JsonFactory                        $resultFactory,
        protected readonly Config          $config,
        protected readonly AddTrackService $addTrack,
        protected readonly OrderActionLogger $orderLogger,
        protected readonly ActivityLogger  $activityLogger,
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

            $this->orderLogger->writeDaily("[shipment_add_track] Received add track request for Shipment ID: $shipmentId — payload: $raw");

            $result = $this->addTrack->execute($shipmentId, $payload);

            $this->orderLogger->writeDaily('[shipment_add_track] Result: ' . json_encode($result));

            $added = array_column($result['added'], 'track_number');
            $this->activityLogger->success(
                'shipment_add_track',
                'shipment',
                $result['shipment_increment_id'],
                'Tracks added: ' . ($added ? implode(', ', $added) : 'none')
                . ($result['skipped'] ? '; skipped (duplicate): ' . implode(', ', $result['skipped']) : '')
            );

            return $this->jsonResponse($result);
        } catch (Exception $e) {
            $status = match (true) {
                in_array($e->getMessage(), ['Unauthorized', 'Unauthorized token'], true) => 401,
                $e instanceof NoSuchEntityException => 404,
                $e instanceof InputException        => 422,
                default                             => 500,
            };
            $this->orderLogger->writeDaily('[shipment_add_track] Error adding track: ' . $e->getMessage(), 'error');
            $this->activityLogger->error('shipment_add_track', 'shipment', $shipmentId ?: null, $e->getMessage());
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
