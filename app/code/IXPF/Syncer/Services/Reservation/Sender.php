<?php

declare(strict_types=1);

namespace IXPF\Syncer\Services\Reservation;

use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\ReservationLogger;
use IXPF\Syncer\Model\IxpfReservation;
use IXPF\Syncer\Model\ResourceModel\IxpfReservation as ReservationResource;
use IXPF\Syncer\Services\Notification\SlackNotifier;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;

/**
 * POSTs a reservation batch to IXPF.
 *
 * Two ways out, for two kinds of caller:
 *   - sendAsync(), used by the order observers. Writes the request and hangs up
 *     without reading the reply, so nothing a customer or an admin is waiting on
 *     is held open by the ERP. The outcome arrives later on the acknowledgement
 *     endpoint instead.
 *   - send(), used by the dispatch and retry crons. Blocking, reads the reply and
 *     records the outcome itself. Nobody is waiting on a cron, so it can afford to.
 *
 * claimBatch() is what stops those two from sending the same batch twice.
 */
class Sender
{
    private const CONNECT_TIMEOUT = 3;
    private const TIMEOUT = 10;

    /**
     * Cap on the TCP (and TLS) handshake for a fire-and-forget send.
     *
     * Shorter than CONNECT_TIMEOUT because this one runs inside a request a
     * person is waiting on. The handshake is the only part that can block —
     * after it we write and close without reading.
     */
    private const ASYNC_CONNECT_TIMEOUT = 1;

    public function __construct(
        private readonly Config $config,
        private readonly ReservationResource $reservationResource,
        private readonly ReservationLogger $logger,
        private readonly SlackNotifier $slackNotifier
    ) {
    }

    /**
     * Send without waiting for the reply.
     *
     * The batch is left in `processing` carrying its `acknowledged_id`. IXPF
     * reports the outcome by POSTing that id back to
     * /ixpfsyncer/V1_Reservations/Acknowledge, which is what moves the batch to
     * success or failed.
     *
     * If that acknowledgement never comes, recoverStuckBatches() releases the
     * batch after ReservationResource::STUCK_MINUTES and the dispatch cron sends
     * it again — this time blocking, so a reply read inline can settle it.
     *
     * Nothing here is allowed to throw: the observers run inside order save.
     */
    public function sendAsync(string $batchId): void
    {
        try {
            if (!$this->reservationResource->claimBatch($batchId)) {
                return;
            }

            $rows = $this->reservationResource->getBatch($batchId);
            if (!$rows) {
                return;
            }

            $payload = $this->buildPayload($rows);

            if (!$payload['products']) {
                $this->reservationResource->markBatch(
                    $batchId,
                    IxpfReservation::REQUEST_SKIPPED,
                    'Nothing to send: batch has no product lines'
                );

                return;
            }

            $payloadJson = (string)json_encode($payload);
            $url = $this->resolveUrl($batchId, $payloadJson);

            if ($url === null) {
                return;
            }

            $written = $this->fireAndForget($url, $payloadJson);

            if (!$written) {
                $this->fail($batchId, 'Could not open a connection to IXPF', null, $payloadJson);

                return;
            }

            // Still `processing`: the send left, but only the acknowledgement can
            // say whether IXPF accepted it. Recording the payload here is what
            // makes the row readable while it waits.
            $this->reservationResource->recordSent($batchId, $payloadJson);

            $this->logger->writeDaily(sprintf(
                'Reservation batch %s sent to IXPF (acknowledged_id %d), awaiting acknowledgement.',
                $batchId,
                $payload['acknowledged_id']
            ));
        } catch (Throwable $e) {
            $this->logger->writeExceptionDaily($e, 'Reservation async send exception');
        }
    }

    /**
     * Claim a batch and POST it. Safe to call concurrently.
     *
     * @return bool True when this call owned the batch and completed the send.
     */
    public function send(string $batchId): bool
    {
        if (!$this->reservationResource->claimBatch($batchId)) {
            return false;
        }

        $rows = $this->reservationResource->getBatch($batchId);
        if (!$rows) {
            return false;
        }

        $payload = $this->buildPayload($rows);

        // A batch with no products is a bookkeeping row, not something to send.
        // Without this the retry cron would POST {"products":[]} once a minute
        // until it hit the attempt cap, alerting Slack each time.
        if (!$payload['products']) {
            $this->reservationResource->markBatch(
                $batchId,
                IxpfReservation::REQUEST_SKIPPED,
                'Nothing to send: batch has no product lines'
            );

            return true;
        }

        $payloadJson = (string)json_encode($payload);
        $url = $this->resolveUrl($batchId, $payloadJson);

        if ($url === null) {
            return true;
        }

        $this->dispatch($batchId, $url, $payload, $payloadJson);

        return true;
    }

    /**
     * The endpoint to POST to, or null when the module is not configured for it.
     *
     * Marks the batch failed and alerts Slack on the way out, so both send paths
     * report a missing setting the same way.
     */
    private function resolveUrl(string $batchId, string $payloadJson): ?string
    {
        $url = $this->config->getProductWebhookUrl();
        $secret = $this->config->getProductWebhookSecret();
        $shopObjectId = $this->config->getShopObjectId();

        if ($url && $secret && $shopObjectId) {
            return $url . '?token=' . $secret;
        }

        $missing = implode(', ', array_filter([
            !$url ? 'Webhook URL' : null,
            !$secret ? 'Webhook Secret' : null,
            !$shopObjectId ? 'Shop Object ID' : null,
        ]));

        $this->reservationResource->markBatch(
            $batchId,
            IxpfReservation::REQUEST_FAILED,
            'Webhook configuration missing: ' . $missing,
            null,
            $payloadJson
        );
        $this->slackNotifier->alert('Reservation Webhook Configuration Missing', [[
            'title' => 'Details',
            'value' => 'Missing: ' . $missing,
            'short' => false,
        ]]);

        return null;
    }

    /**
     * Write the POST and hang up without reading the reply.
     *
     * Guzzle cannot do this — every transport it has reads the response before
     * returning, which is the waiting we are trying to avoid. A raw socket can:
     * connect, write, close. The only blocking part left is the handshake, capped
     * at ASYNC_CONNECT_TIMEOUT.
     *
     * The body is small enough to land in the kernel's send buffer in one write,
     * so closing immediately does not truncate it.
     *
     * @return bool False when the connection could not be opened at all.
     */
    private function fireAndForget(string $url, string $payloadJson): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }

        $secure = ($parts['scheme'] ?? 'http') === 'https';
        $host = $parts['host'];
        $port = (int)($parts['port'] ?? ($secure ? 443 : 80));
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        // A refused or unroutable host raises a warning before it returns false.
        // Swallowing it here keeps a dead ERP out of the PHP log on every order,
        // without reaching for the error-suppression operator.
        set_error_handler(static fn (): bool => true);

        try {
            $socket = stream_socket_client(
                ($secure ? 'ssl://' : 'tcp://') . $host . ':' . $port,
                $errno,
                $errstr,
                self::ASYNC_CONNECT_TIMEOUT
            );
        } finally {
            restore_error_handler();
        }

        if (!$socket) {
            return false;
        }

        $request = sprintf(
            "POST %s HTTP/1.1\r\nHost: %s\r\nContent-Type: application/json\r\n"
            . "Content-Length: %d\r\nConnection: close\r\n\r\n%s",
            $path,
            $host . ($port === ($secure ? 443 : 80) ? '' : ':' . $port),
            strlen($payloadJson),
            $payloadJson
        );

        stream_set_timeout($socket, self::ASYNC_CONNECT_TIMEOUT);

        set_error_handler(static fn (): bool => true);

        try {
            $written = fwrite($socket, $request);
        } finally {
            restore_error_handler();
            fclose($socket);
        }

        return $written !== false && $written > 0;
    }

    /**
     * Build the body IXPF expects.
     *
     * `status` is the batch's lifecycle stage, so the same endpoint carries the
     * placement and every later transition. Quantities go out positive — the
     * table keeps them signed, IXPF reads the magnitude alongside `status`.
     *
     * `acknowledged_id` is the entity_id of the batch's first row. A batch is
     * several rows — one per SKU line — but IXPF acknowledges the batch as a
     * whole, so one id stands for all of them. getBatch() orders by entity_id
     * ascending, which is what makes "first row" the same id on every re-send.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function buildPayload(array $rows): array
    {
        $first = $rows[0];

        $products = [];
        foreach ($rows as $row) {
            if ($row['sku'] === '' || (float)$row['qty'] == 0.0) {
                continue;
            }
            $products[] = [
                'sku' => (string)$row['sku'],
                'qty' => abs((float)$row['qty']),
                'source' => (string)$row['stock_source'],
            ];
        }

        return [
            'shop_object_id' => $this->config->getShopObjectId(),
            'type' => 'reservation',
            'status' => (string)$first['reservation_status'],
            'acknowledged_id' => (int)$first[ReservationResource::ENTITY],
            'order_id' => $first['order_id'] !== null ? (int)$first['order_id'] : null,
            'products' => $products,
        ];
    }

    /**
     * POST and translate the reply into a batch status.
     *
     * Guzzle rather than raw curl, matching the other payload-carrying calls in
     * this module (ProductBulkUpdate\ProductUpdate, PhotoStudio\Webhook). The
     * thin curl in Helper\Webhook predates them and sends no body worth speaking of.
     *
     * @param array<string, mixed> $payload
     */
    private function dispatch(string $batchId, string $url, array $payload, string $payloadJson): void
    {
        try {
            $client = new Client([
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'timeout' => self::TIMEOUT,
                // 4xx/5xx come back as responses, not exceptions, so a business
                // rejection carrying {"success":false,"message":...} is still readable.
                'http_errors' => false,
            ]);

            $httpResponse = $client->post($url, ['json' => $payload]);
            $response = (string)$httpResponse->getBody();
            $httpCode = $httpResponse->getStatusCode();
        } catch (ConnectException $e) {
            $this->fail($batchId, 'Connection error: ' . $this->redact($e->getMessage()), null, $payloadJson);
            return;
        } catch (GuzzleException $e) {
            $this->fail($batchId, 'Request error: ' . $this->redact($e->getMessage()), null, $payloadJson);
            return;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->fail($batchId, sprintf('HTTP %d', $httpCode), $response, $payloadJson);
            return;
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded) || !array_key_exists('success', $decoded)) {
            $this->fail($batchId, 'Unparseable IXPF response', $response, $payloadJson);
            return;
        }

        if ($decoded['success'] === true) {
            // IXPF may send a message alongside success — keep it. Only a blank
            // one becomes null, so the column stays empty when there is nothing
            // to say rather than holding an empty string.
            $message = trim((string)($decoded['message'] ?? ''));

            $this->reservationResource->markBatch(
                $batchId,
                IxpfReservation::REQUEST_SUCCESS,
                $message !== '' ? $message : null,
                $response,
                $payloadJson
            );
            $this->logger->writeDaily(sprintf(
                'Reservation batch %s accepted by IXPF%s',
                $batchId,
                $message !== '' ? ': ' . $message : ''
            ));
            return;
        }

        // success:false is a business rejection — IXPF's own message is the useful part.
        $this->fail(
            $batchId,
            (string)($decoded['message'] ?? 'IXPF rejected the reservation'),
            $response,
            $payloadJson
        );
    }

    /**
     * Strip the webhook secret out of a message before it is stored or alerted.
     *
     * Guzzle puts the full request URI in its exception messages, which means the
     * ?token= query param would otherwise land in the information column, the
     * reservation log and the Slack alert.
     */
    private function redact(string $message): string
    {
        return (string)preg_replace('/([?&]token=)[^\s&"\']+/i', '$1***', $message);
    }

    private function fail(string $batchId, string $information, ?string $response, string $payloadJson): void
    {
        $this->reservationResource->markBatch(
            $batchId,
            IxpfReservation::REQUEST_FAILED,
            $information,
            $response,
            $payloadJson
        );

        $this->logger->writeDaily(
            sprintf('Reservation batch %s failed: %s', $batchId, $information),
            'error'
        );

        $this->slackNotifier->alert('Reservation Webhook Failed', [[
            'title' => 'Batch ' . $batchId,
            'value' => $information,
            'short' => false,
        ]]);
    }
}
