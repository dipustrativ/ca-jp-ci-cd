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
     * person is waiting on, but it has to cover DNS + TCP + the full TLS
     * handshake to a WAN endpoint. 1s was enough for a tunnel on the same
     * continent and not enough for the real ERP.
     */
    private const ASYNC_CONNECT_TIMEOUT = 3;

    /**
     * Cap on waiting for the status line after the request has been written.
     *
     * We read the first line only — never the body — so this costs the
     * endpoint's time-to-first-byte, not its processing time. Without it a
     * 401/403/500 is invisible: the write succeeds either way, and the batch
     * sits in `processing` until the retry cron releases it, with nothing in
     * the log to say why.
     */
    private const ASYNC_STATUS_TIMEOUT = 2;

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

            $error = null;
            $written = $this->fireAndForget($url, $payloadJson, $error);

            if (!$written) {
                $this->fail(
                    $batchId,
                    'Could not deliver to IXPF: ' . ($error ?? 'unknown transport error'),
                    null,
                    $payloadJson
                );

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
     * Write the POST and hang up without reading the body.
     *
     * Guzzle cannot do this — every transport it has reads the whole response
     * before returning, which is the waiting we are trying to avoid. A raw
     * socket can: connect, write, take the status line, close.
     *
     * Three things here are not optional, all of them learned the hard way:
     *
     *  1. fwrite() has to be looped. It returns after handing bytes to the
     *     stream's own buffer, so a short write on a busy socket is normal and
     *     silent.
     *  2. stream_socket_shutdown(STREAM_SHUT_WR) has to come before fclose().
     *     fclose() on a stream that still has buffered bytes DISCARDS them —
     *     measured: a 200 KB request arrived as 114 KB, truncated mid-body,
     *     while fwrite() reported all 200 KB written and this method returned
     *     true. Shutting the write side down first flushes, then sends FIN,
     *     which is also what tells the endpoint the body is complete.
     *  3. The status line has to be read. Closing the socket the instant the
     *     request is out makes us a client that disconnected early, and every
     *     reverse proxy in front of an ERP (nginx logs it 499) abandons the
     *     request rather than passing it upstream. Reading one line both keeps
     *     the connection alive long enough to be served and turns a silent
     *     401/403/500 into something that reaches the log.
     *
     * We still never read the body, so IXPF's processing time is not ours.
     *
     * @return bool False when the request could not be delivered.
     */
    private function fireAndForget(string $url, string $payloadJson, ?string &$error = null): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            $error = 'Malformed webhook URL';
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
            $error = sprintf('Connect failed [%d] %s', (int)$errno, (string)$errstr);
            return false;
        }

        // User-Agent and Accept are not decoration: a WAF in front of the ERP
        // will drop a POST that carries neither, and a fire-and-forget send has
        // no way to notice.
        $request = sprintf(
            "POST %s HTTP/1.1\r\nHost: %s\r\nUser-Agent: CarpetAvenue-Magento/1.0\r\n"
            . "Accept: application/json\r\nContent-Type: application/json\r\n"
            . "Content-Length: %d\r\nConnection: close\r\n\r\n%s",
            $path,
            $host . ($port === ($secure ? 443 : 80) ? '' : ':' . $port),
            strlen($payloadJson),
            $payloadJson
        );

        $length = strlen($request);
        $sent = 0;
        $status = null;

        stream_set_timeout($socket, self::ASYNC_CONNECT_TIMEOUT);
        set_error_handler(static fn (): bool => true);

        try {
            while ($sent < $length) {
                $written = fwrite($socket, substr($request, $sent));
                if ($written === false || $written === 0) {
                    break;
                }
                $sent += $written;
            }

            if ($sent === $length) {
                // Flush, then half-close. Without this fclose() throws away
                // whatever is still buffered.
                stream_socket_shutdown($socket, STREAM_SHUT_WR);

                stream_set_timeout($socket, self::ASYNC_STATUS_TIMEOUT);
                $status = fgets($socket, 128);
            }
        } finally {
            restore_error_handler();
            fclose($socket);
        }

        if ($sent !== $length) {
            $error = sprintf('Short write: %d of %d bytes', $sent, $length);
            return false;
        }

        // No status line inside the cap is not a failure — the endpoint may
        // simply be slow to answer, and the acknowledgement settles the batch
        // either way. Only a status we did read and did not like is.
        if (is_string($status) && preg_match('#^HTTP/\d(?:\.\d)? (\d{3})#', $status, $m)) {
            $code = (int)$m[1];
            if ($code < 200 || $code >= 300) {
                $error = sprintf('IXPF answered HTTP %d', $code);
                return false;
            }
        }

        return true;
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
            // A 2xx carrying something other than the expected JSON is IXPF's
            // own trouble leaking through — an upstream timeout of theirs comes
            // back as bare Go error text. fail() keeps the body on the row; this
            // line puts it in the log too, with the batch and status beside it.
            $this->logger->writeDaily(
                sprintf(
                    'Unparseable IXPF response for batch %s (HTTP %d): %s',
                    $batchId,
                    $httpCode,
                    $response
                ),
                'error'
            );
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
