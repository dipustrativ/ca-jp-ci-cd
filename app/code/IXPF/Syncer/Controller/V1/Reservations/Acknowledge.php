<?php

declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Reservations;

use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\ReservationLogger;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Model\IxpfReservation;
use IXPF\Syncer\Model\ResourceModel\IxpfReservation as ReservationResource;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Throwable;

/**
 * IXPF's answer to a reservation we sent.
 *
 * POST /ixpfsyncer/V1_Reservations/Acknowledge
 *
 * Note the underscore — same routing rule as Apply: Magento's Base::parseRequest
 * consumes exactly three path segments and ActionList maps `_` to `\`, so the
 * slash form 302s and never reaches a controller.
 *
 *   {"success": true, "acknowledged_id": 1212, "message": ""}
 *
 * Outbound reservations are written and hung up on without reading a reply, so
 * this endpoint is the only thing that moves a batch off `processing`. Batches
 * never acknowledged are released by recoverStuckBatches() and sent again.
 */
class Acknowledge extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        private readonly Config $config,
        private readonly ReservationResource $reservationResource,
        private readonly ReservationLogger $logger
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute(): Json
    {
        try {
            $this->tokenValidator();

            if (!$this->config->isReservationEnabled()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Reservations are disabled in configuration.',
                ], 422);
            }

            // getContent() lives on the HTTP request, not on RequestInterface.
            /** @var HttpRequest $request */
            $request = $this->getRequest();
            $raw = (string)$request->getContent();
            $payload = json_decode($raw, true);

            if (!is_array($payload) || !isset($payload['acknowledged_id'])
                || !is_numeric($payload['acknowledged_id'])
            ) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Payload must contain a numeric acknowledged_id.',
                ], 422);
            }

            $acknowledgedId = (int)$payload['acknowledged_id'];
            $batchId = $this->reservationResource->getBatchIdByEntityId($acknowledgedId);

            if ($batchId === null) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => sprintf('No outbound reservation found for acknowledged_id %d.', $acknowledgedId),
                ], 404);
            }

            // Absent success is treated as a rejection: an acknowledgement that
            // does not say it worked has not said it worked.
            $accepted = ($payload['success'] ?? false) === true;
            $message = trim((string)($payload['message'] ?? ''));

            $this->reservationResource->markAcknowledged(
                $batchId,
                $accepted ? IxpfReservation::REQUEST_SUCCESS : IxpfReservation::REQUEST_FAILED,
                $message !== '' ? $message : ($accepted ? null : 'IXPF rejected the reservation'),
                $raw
            );

            $this->logger->writeDaily(
                sprintf(
                    'Reservation batch %s acknowledged by IXPF (acknowledged_id %d): %s%s',
                    $batchId,
                    $acknowledgedId,
                    $accepted ? 'accepted' : 'rejected',
                    $message !== '' ? ' — ' . $message : ''
                ),
                $accepted ? 'info' : 'error'
            );

            return $this->jsonResponse([
                'success' => true,
                'message' => '',
                'acknowledged_id' => $acknowledgedId,
                'batch_id' => $batchId,
                'request_status' => $accepted
                    ? IxpfReservation::REQUEST_SUCCESS
                    : IxpfReservation::REQUEST_FAILED,
            ]);
        } catch (Throwable $e) {
            $this->logger->writeExceptionDaily($e, 'Reservation acknowledgement exception');

            return $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
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
