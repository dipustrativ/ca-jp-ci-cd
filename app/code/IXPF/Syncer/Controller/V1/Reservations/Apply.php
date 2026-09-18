<?php

declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Reservations;

use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\ReservationLogger;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Model\IxpfReservation;
use IXPF\Syncer\Services\Reservation\Apply as ApplyService;
use IXPF\Syncer\Services\Reservation\IdempotencyGuard;
use IXPF\Syncer\Services\Reservation\Recorder;
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
 * Inbound reservation adjustments from IXPF.
 *
 * POST /ixpfsyncer/V1_Reservations/Apply
 *
 * Note the underscore. Magento's Base::parseRequest consumes exactly three path
 * segments and ActionList maps `_` to `\`, so the slash form used by some of the
 * older docs in this module 302s and never reaches a controller.
 *
 *   {"idempotency_key":"ixpf-9f3c","products":[{"sku":"sku101","qty":-1,"source":"hamburg"}]}
 *
 * qty is signed and relative: negative removes stock from that source, positive
 * returns it.
 *
 * idempotency_key is optional but single-use: a second request carrying a key
 * already seen is refused with 409 and nothing is written. Because qty is
 * relative, replaying a batch moves the stock again rather than landing on the
 * same number, so a retry of a request that did go through has to be caught.
 */
class Apply extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        private readonly Config $config,
        private readonly ApplyService $applyService,
        private readonly Recorder $recorder,
        private readonly IdempotencyGuard $idempotencyGuard,
        private readonly ReservationLogger $logger
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute(): Json
    {
        try {
            $this->tokenValidator();

            if (!$this->config->isReservationInboundEnabled()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Reservation inbound endpoint is disabled in configuration.',
                ], 422);
            }

            // getContent() lives on the HTTP request, not on RequestInterface.
            /** @var HttpRequest $request */
            $request = $this->getRequest();
            $payload = json_decode((string)$request->getContent(), true);

            if (!is_array($payload) || !isset($payload['products']) || !is_array($payload['products'])
                || $payload['products'] === []
            ) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Payload must contain a non-empty products array.',
                ], 422);
            }

            $idempotencyKey = $payload['idempotency_key'] ?? null;

            if ($idempotencyKey !== null && (!is_string($idempotencyKey) || trim($idempotencyKey) === '')) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'idempotency_key must be a non-empty string when supplied.',
                ], 422);
            }

            $idempotencyKey = $idempotencyKey === null ? null : trim($idempotencyKey);

            // Rejected rather than truncated: a key cut to fit the column would
            // collide with a different request that shares its first 128 chars.
            if ($idempotencyKey !== null && mb_strlen($idempotencyKey) > IdempotencyGuard::MAX_LENGTH) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => sprintf(
                        'idempotency_key must be %d characters or fewer.',
                        IdempotencyGuard::MAX_LENGTH
                    ),
                ], 422);
            }

            $this->logger->writeDaily('Inbound reservation: ' . json_encode($payload));

            $batchId = Recorder::generateBatchId();

            // Claimed before anything is recorded or applied, so a duplicate
            // leaves no rows behind and cannot half-apply.
            if ($idempotencyKey !== null) {
                $original = $this->idempotencyGuard->claim($idempotencyKey, $batchId);

                if ($original !== null) {
                    $this->logger->writeDaily(sprintf(
                        'Inbound reservation rejected: idempotency_key %s already used by batch %s at %s',
                        $idempotencyKey,
                        $original['batch_id'],
                        $original['created_at']
                    ));

                    return $this->jsonResponse([
                        'success' => false,
                        'message' => sprintf(
                            "Duplicate request: idempotency_key '%s' was already taken."
                                . ' Nothing was applied. Send a new key to submit a new adjustment.',
                            $idempotencyKey
                        ),
                        'idempotency_key' => $idempotencyKey,
                        'original_batch_id' => $original['batch_id'],
                        'original_received_at' => $original['created_at'],
                    ], 409);
                }
            }

            // Record before applying, so a crash mid-write still leaves a trace.
            $this->recordIncoming($payload['products'], $batchId, $idempotencyKey);

            $result = $this->applyService->execute($payload['products'], $batchId);

            $success = $result['summary']['failed'] === 0;

            return $this->jsonResponse([
                'success' => $success,
                'message' => $success
                    ? ''
                    : sprintf('%d of %d lines failed.', $result['summary']['failed'], $result['summary']['total']),
                'batch_id' => $batchId,
                'summary' => $result['summary'],
                'applied' => $result['applied'],
                'failed' => $result['failed'],
            ], $success ? 200 : 422);
        } catch (Throwable $e) {
            $this->logger->writeExceptionDaily($e, 'Inbound reservation exception');

            return $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Persist the request as it arrived, before anything is validated or written.
     *
     * @param array<int, mixed> $products
     */
    private function recordIncoming(array $products, string $batchId, ?string $idempotencyKey): string
    {
        $lines = [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $lines[] = [
                'sku' => trim((string)($product['sku'] ?? '')),
                'qty' => (float)($product['qty'] ?? 0),
                'source' => trim((string)($product['source'] ?? '')),
            ];
        }

        return $this->recorder->record(
            IxpfReservation::DIRECTION_INBOUND,
            IxpfReservation::RESERVATION_PENDING,
            null,
            $lines,
            null,
            IxpfReservation::REQUEST_PENDING,
            null,
            $idempotencyKey,
            $batchId
        );
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
