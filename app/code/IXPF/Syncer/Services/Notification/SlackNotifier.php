<?php

declare(strict_types=1);

namespace IXPF\Syncer\Services\Notification;

use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\NotificationLogger;
use IXPF\Syncer\Helper\Logger\WebhookLogger;

/**
 * Sends Slack notifications.
 *
 * Usage examples:
 *
 *   $this->slackNotifier->alert('Payment Failed', ['Order #1001' => '3 failures', 'Order #1002' => '1 failure']);
 *   $this->slackNotifier->warning('Low Stock', ['SKU-123' => '2 units left']);
 *   $this->slackNotifier->success('Sync Completed', ['Products synced' => '150', 'Duration' => '4s']);
 *   $this->slackNotifier->info('Cron Started', ['Job' => 'webhook_dispatch']);
 *   $this->slackNotifier->sendMessage('Any plain text message.');
 */
class SlackNotifier
{
    public function __construct(
        private readonly Config             $config,
        private readonly WebhookLogger      $logger,
        private readonly NotificationLogger $notificationLogger
    ) {
    }

    /**
     * Send a :rotating_light: ALERT (red) notification.
     *
     * @param string              $title   Short title, e.g. "Failed Jobs Detected"
     * @param array<string,mixed> $details Key-value pairs with context information
     */
    public function alert(string $title, array $details = []): void
    {
        $this->dispatch('alert', $title, $details);
    }

    /**
     * Send a :warning: WARNING (yellow) notification.
     *
     * @param string              $title
     * @param array<string,mixed> $details
     */
    public function warning(string $title, array $details = []): void
    {
        $this->dispatch('warning', $title, $details);
    }

    /**
     * Send a :white_check_mark: SUCCESS (green) notification.
     *
     * @param string              $title
     * @param array<string,mixed> $details
     */
    public function success(string $title, array $details = []): void
    {
        $this->dispatch('success', $title, $details);
    }

    /**
     * Send a :information_source: INFO (blue) notification.
     *
     * @param string              $title
     * @param array<string,mixed> $details
     */
    public function info(string $title, array $details = []): void
    {
        $this->dispatch('info', $title, $details);
    }

    /**
     * Send any arbitrary plain-text message to the configured Slack channel.
     */
    public function sendMessage(string $message): void
    {
        $this->dispatch('info', $message, []);
    }

    /** Returns [emoji, label, hex-color] for each level. */
    private function levelMeta(string $level): array
    {
        return match ($level) {
            'alert'   => [':rotating_light:',    'ALERT',   '#E53935'],
            'warning' => [':warning:',            'WARNING', '#FB8C00'],
            'success' => [':white_check_mark:',   'SUCCESS', '#43A047'],
            'info'    => [':information_source:', 'INFO',    '#1E88E5'],
            default   => [':speech_balloon:',     'MESSAGE', '#546E7A'],
        };
    }

    /**
     * Build a richly-formatted Slack attachment payload and send it.
     *
     * @param string              $level   alert | warning | success | info
     * @param string              $title
     * @param array<string,mixed> $details
     */
    private function dispatch(string $level, string $title, array $details): void
    {
        if (!$this->config->isSlackNotificationEnabled()) {
            $this->writeToFile($level, $title, $details);
            return;
        }

        [$emoji, $label, $color] = $this->levelMeta($level);

        $fallback = strtoupper($label) . ': ' . $title;

        // ── Header block
        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type'  => 'plain_text',
                    'text'  => $emoji . '  ' . $label . ' — ' . $title,
                    'emoji' => true,
                ],
            ],
            ['type' => 'divider'],
        ];

        // ── Details section (rendered as a 2-column field grid)
        if (!empty($details)) {
            $fields = [];
            foreach ($details as $key => $value) {
                // Support legacy attachment-style entries: ['title' => ..., 'value' => ...]
                if (is_array($value) && array_key_exists('title', $value)) {
                    $fieldTitle = (string) ($value['title'] ?? $key);
                    $fieldValue = isset($value['value'])
                        ? (is_array($value['value']) ? json_encode($value['value']) : (string) $value['value'])
                        : '';
                    $fields[] = [
                        'type' => 'mrkdwn',
                        'text' => "*{$fieldTitle}*\n```{$fieldValue}```",
                    ];
                } else {
                    $scalar = is_array($value) ? json_encode($value) : (string) $value;
                    $fields[] = [
                        'type' => 'mrkdwn',
                        'text' => "*{$key}*\n`{$scalar}`",
                    ];
                }
            }

            // Slack supports max 10 fields per section
            foreach (array_chunk($fields, 10) as $chunk) {
                $blocks[] = [
                    'type'   => 'section',
                    'fields' => $chunk,
                ];
            }

            $blocks[] = ['type' => 'divider'];
        }

        // ── Footer context block
        $blocks[] = [
            'type'     => 'context',
            'elements' => [
                [
                    'type' => 'mrkdwn',
                    'text' => ':clock3:  *' . date('Y-m-d H:i:s') . '*  |  :earth_asia:  *Carpet Avenue*',
                ],
            ],
        ];

        $payload = json_encode([
            'attachments' => [
                [
                    'color'    => $color,
                    'fallback' => $fallback,
                    'blocks'   => $blocks,
                ],
            ],
        ]);

        $this->sendToSlack($payload);
    }

    /**
     * Fallback when Slack notifications are disabled: write the message to file.
     *
     * @param string              $level   alert | warning | success | info
     * @param string              $title
     * @param array<string,mixed> $details
     */
    private function writeToFile(string $level, string $title, array $details): void
    {
        [, $label] = $this->levelMeta($level);

        $line = strtoupper($label) . ': ' . $title;

        if (!empty($details)) {
            $flat = [];
            foreach ($details as $key => $value) {
                if (is_array($value) && array_key_exists('title', $value)) {
                    $fieldTitle = (string) ($value['title'] ?? $key);
                    $fieldValue = isset($value['value'])
                        ? (is_array($value['value']) ? json_encode($value['value']) : (string) $value['value'])
                        : '';
                    $flat[] = $fieldTitle . '=' . $fieldValue;
                } else {
                    $scalar = is_array($value) ? json_encode($value) : (string) $value;
                    $flat[] = $key . '=' . $scalar;
                }
            }
            $line .= ' | ' . implode(' | ', $flat);
        }

        $this->notificationLogger->writeDaily($line, $level === 'alert' ? 'error' : 'info');
    }

    /**
     * POST a raw JSON payload to the Slack Incoming Webhook URL.
     */
    private function sendToSlack(string $payload): void
    {
        $webhookUrl = $this->config->getSlackWebhookUrl();

        if (!$webhookUrl) {
            $this->logger->writeDaily('SlackNotifier: Slack webhook URL is not configured.');
            return;
        }


        try {
            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno    = curl_errno($ch);
            $error    = $errno ? curl_error($ch) : null;
            unset($ch);

            if ($errno || $httpCode < 200 || $httpCode >= 300) {
                $this->logger->writeDaily(
                    sprintf(
                        'SlackNotifier: Request failed. HTTP %d | cURL errno %d | error: %s | response: %s',
                        $httpCode,
                        $errno,
                        (string) $error,
                        (string) $response
                    )
                );
            }
        } catch (\Throwable $e) {
            $this->logger->writeExceptionDaily($e, 'SlackNotifier: Exception while sending Slack notification');
        }
    }
}
