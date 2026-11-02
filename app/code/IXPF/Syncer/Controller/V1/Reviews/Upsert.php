<?php

declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Reviews;

use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\Logger\ReviewLogger;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Services\Reviews\UpsertReviewGroup;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Throwable;

/**
 * Review pool upsert.
 *
 * POST /ixpfsyncer/V1_Reviews/Upsert
 * Header: X-Auth-Token: Bearer <integration token>
 *
 * The underscore is required. Magento's frontend router takes three path segments and turns
 * `_` in the action path into a namespace separator, so `V1_Reviews` maps to Controller/V1/Reviews.
 * The slashed form `/ixpfsyncer/V1/Reviews/Upsert` never reaches this class - it resolves to a
 * non-existent Controller/V1/Reviews.php and 302s to the storefront.
 *
 * One call replaces the entire review set for one review_group. Anything absent from the
 * payload is deleted. A stale push returns 200, not an error — any 4xx or 5xx there would
 * make IXPF retry a push that can never succeed.
 *
 * Deliberately not gated on ixpf_reviews/general/enabled: IXPF must be able to backfill
 * while everything downstream is still off.
 *
 * Every call is written to var/log/ixpf/review/review-<Y-m-d>.log in full - headers, the raw
 * body exactly as posted, and the response sent back - so a disputed push can be replayed from
 * the log alone. What the database then did with it (added / updated / removed / skipped, or
 * ignored as stale, or rolled back) is written to the same file by UpsertReviewGroup, between
 * this request's `>>` and `<<` lines.
 */
class Upsert extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * Headers whose value never reaches the log file. The integration token and IXPF's partner
     * secret arrive on every request and a log file is not a place to keep credentials.
     */
    private const REDACTED_HEADERS = ['x-auth-token', 'authorization', 'cookie', 'x-api-key', 'ixpf-partner-secret'];

    /**
     * Catch-all for credential headers not listed above. Ixpf-Partner-Secret was written to every
     * log in plain text because it was missing from the list; a name like this is never logged.
     */
    private const REDACTED_HEADER_PATTERN = '/secret|token|password/i';

    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        private readonly UpsertReviewGroup $service,
        private readonly ReviewLogger $logger,
        private readonly RemoteAddress $remoteAddress
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute(): Json
    {
        $requestId = bin2hex(random_bytes(4));

        try {
            $this->tokenValidator();
        } catch (Throwable $e) {
            // Logged without its body. This endpoint is public and unauthenticated callers are
            // not IXPF, so writing an attacker-chosen payload of any size to disk on every
            // rejected request is a way to fill the volume, not an audit trail. Headers and the
            // byte count are enough to see who is knocking.
            $this->logRequest($requestId, false);

            return $this->respond($requestId, ['message' => $e->getMessage()], 401);
        }

        $this->logRequest($requestId, true);

        try {
            $payload = json_decode((string)$this->getRequest()->getContent(), true);

            if (!is_array($payload)) {
                return $this->respond(
                    $requestId,
                    ['message' => 'Request body must be a JSON object', 'field' => 'payload'],
                    400
                );
            }

            $result = $this->service->upsert($payload);

            if ($result['status'] === 'invalid') {
                return $this->respond(
                    $requestId,
                    ['message' => $result['message'], 'field' => $result['field']],
                    400
                );
            }

            return $this->respond($requestId, $result, 200);
        } catch (Throwable $e) {
            // The service already rolled back and logged the failure with the group key in the
            // activity file; this is the copy that reaches var/log/ixpf/exception, carrying the
            // request id so it can be tied back to the body that caused it.
            $this->logger->writeExceptionDaily($e, sprintf('[%s] Review upsert failed', $requestId));

            return $this->respond($requestId, ['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Writes the incoming call to the review log, tagged with the id its response will carry.
     *
     * An authenticated body is logged verbatim, not re-encoded from the decoded array: only the
     * exact bytes IXPF posted can settle an argument about what was sent. It is not truncated
     * either - a pool push is a few hundred KB at worst, and a half-logged payload is not worth
     * keeping.
     */
    private function logRequest(string $requestId, bool $authenticated): void
    {
        $request = $this->getRequest();
        $body    = (string)$request->getContent();

        $this->logger->writeDaily(sprintf(
            "[%s] >> %s %s from %s\nHeaders: %s\nBody: %s",
            $requestId,
            $request->getMethod(),
            $request->getUriString(),
            (string)$this->remoteAddress->getRemoteAddress(),
            $this->encode($this->safeHeaders()),
            $authenticated
                ? $body
                : sprintf('<%d bytes, not logged: unauthenticated>', strlen($body))
        ));
    }

    /**
     * Logs the response, then sends it. Every exit path in execute() goes through here, so no
     * status code can be returned without a matching line in the log.
     */
    private function respond(string $requestId, array $data, int $statusCode): Json
    {
        $this->logger->writeDaily(sprintf(
            '[%s] << %d %s',
            $requestId,
            $statusCode,
            $this->encode($data)
        ));

        return $this->jsonResponse($data, $statusCode);
    }

    /**
     * @return array<string, string>
     */
    private function safeHeaders(): array
    {
        $headers = [];

        foreach ($this->getRequest()->getHeaders()->toArray() as $name => $value) {
            $redact = in_array(strtolower((string)$name), self::REDACTED_HEADERS, true)
                || preg_match(self::REDACTED_HEADER_PATTERN, (string)$name) === 1;
            $headers[$name] = $redact
                ? '*** redacted ***'
                : $value;
        }

        return $headers;
    }

    /**
     * Slashes and unicode left as-is: this is read by people, not parsed.
     *
     * @param array<mixed> $data
     */
    private function encode(array $data): string
    {
        return (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
