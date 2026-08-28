<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Core
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Core\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Slack Notification Helper
 *
 * Sends messages to Slack via Incoming Webhooks using the Block Kit payload format.
 *
 * @see docs/slack-notification.md  Full setup guide and usage examples.
 * @see https://api.slack.com/block-kit
 */
class Slack extends AbstractHelper
{
    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    private const CHANNEL_DEFAULT = SlackConfig::CHANNEL_DEFAULT;
    private const CHANNEL_WATCHER = SlackConfig::CHANNEL_WATCHER;

    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    /** Active channel identifier (default | watcher) */
    private string $activeChannel = self::CHANNEL_DEFAULT;

    /** Optional one-shot webhook URL override */
    private ?string $webhookOverride = null;

    public function __construct(
        Context          $context,
        private readonly SlackConfig      $slackConfig,
        private readonly Curl             $curl,
        private readonly LoggerInterface  $logger
    ) {
        parent::__construct($context);
    }

    // -------------------------------------------------------------------------
    // Fluent channel selectors
    // -------------------------------------------------------------------------

    /** Send the next message to the Default channel. */
    public function toDefault(): static
    {
        $clone = clone $this;
        $clone->activeChannel   = self::CHANNEL_DEFAULT;
        $clone->webhookOverride = null;
        return $clone;
    }

    /** Send the next message to the Watcher channel. */
    public function toWatcher(): static
    {
        $clone = clone $this;
        $clone->activeChannel   = self::CHANNEL_WATCHER;
        $clone->webhookOverride = null;
        return $clone;
    }

    /** Override the webhook URL for a single send (bypasses config). */
    public function withWebhook(string $webhookUrl): static
    {
        $clone = clone $this;
        $clone->webhookOverride = $webhookUrl;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Sending methods
    // -------------------------------------------------------------------------

    /**
     * Send a plain-text or mrkdwn message.
     *
     * @param  string $text       Message text (supports Slack mrkdwn).
     * @param  array  $extraBlocks Optional additional Block Kit blocks appended after the text block.
     * @return bool   true on success, false on failure / disabled.
     */
    public function send(string $text, array $extraBlocks = []): bool
    {
        $blocks = array_merge(
            [$this->sectionBlock($text)],
            $extraBlocks
        );

        return $this->sendBlocks($blocks, $text);
    }

    /**
     * Send a fully custom Block Kit payload.
     *
     * @param  array       $blocks   Array of Block Kit block objects.
     * @param  string|null $fallback Plain-text fallback shown in notifications / older clients.
     * @return bool
     */
    public function sendBlocks(array $blocks, ?string $fallback = null): bool
    {
        $cfg = $this->resolveChannelConfig();

        if ($cfg === null) {
            return false;
        }

        $payload = $this->buildPayload($cfg, $blocks, $fallback);

        return $this->dispatch($cfg['webhook_url'], $payload);
    }

    /**
     * Send an attachment-style message (legacy, but still widely used for
     * coloured side-bars). Prefer sendBlocks() for new integrations.
     *
     * @param  string $text    Main message text.
     * @param  string $color   Hex colour or 'good' | 'warning' | 'danger'.
     * @param  array  $fields  Optional attachment fields [['title'=>…,'value'=>…,'short'=>bool]].
     * @return bool
     */
    public function sendAttachment(string $text, string $color = '#36a64f', array $fields = []): bool
    {
        $cfg = $this->resolveChannelConfig();

        if ($cfg === null) {
            return false;
        }

        $attachment = array_filter([
            'color'    => $color,
            'text'     => $text,
            'fields'   => $fields ?: null,
            'mrkdwn_in' => ['text'],
        ]);

        $payload = array_filter([
            'username'    => $cfg['bot_username'] ?: null,
            'channel'     => $cfg['channel']      ?: null,
            'icon_url'    => $cfg['icon_url']     ?: null,
            'attachments' => [$attachment],
        ]);

        return $this->dispatch($cfg['webhook_url'], $payload);
    }

    // -------------------------------------------------------------------------
    // Block Kit factory helpers
    // -------------------------------------------------------------------------

    /**
     * Section block (supports mrkdwn).
     *
     *   $slack->sectionBlock('*Hello* world');
     */
    public function sectionBlock(string $text, ?array $accessory = null): array
    {
        $block = [
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => $text],
        ];
        if ($accessory !== null) {
            $block['accessory'] = $accessory;
        }
        return $block;
    }

    /**
     * Header block (plain text, displayed as a large title).
     *
     *   $slack->headerBlock('Order Placed');
     */
    public function headerBlock(string $text): array
    {
        return [
            'type' => 'header',
            'text' => ['type' => 'plain_text', 'text' => $text, 'emoji' => true],
        ];
    }

    /**
     * Divider block.
     */
    public function dividerBlock(): array
    {
        return ['type' => 'divider'];
    }

    /**
     * Context block – small grey text / images at the bottom of a message.
     *
     *   $slack->contextBlock(['Triggered by cron', '2024-01-01 12:00:00']);
     */
    public function contextBlock(array $lines): array
    {
        $elements = array_map(
            static fn(string $line) => ['type' => 'mrkdwn', 'text' => $line],
            $lines
        );
        return ['type' => 'context', 'elements' => $elements];
    }

    /**
     * Fields section – two-column key/value pairs.
     *
     *   $slack->fieldsBlock(['Status' => 'Pending', 'Amount' => '€99.00']);
     */
    public function fieldsBlock(array $keyValues): array
    {
        $fields = [];
        foreach ($keyValues as $label => $value) {
            $fields[] = ['type' => 'mrkdwn', 'text' => "*{$label}*\n{$value}"];
        }
        return ['type' => 'section', 'fields' => $fields];
    }

    /**
     * Button actions block.
     *
     *   $slack->actionsBlock([['text'=>'View Order','url'=>'https://…','action_id'=>'view_order']]);
     */
    public function actionsBlock(array $buttons): array
    {
        $elements = array_map(static function (array $btn) {
            return array_filter([
                'type'      => 'button',
                'text'      => ['type' => 'plain_text', 'text' => $btn['text'], 'emoji' => true],
                'url'       => $btn['url']       ?? null,
                'action_id' => $btn['action_id'] ?? null,
                'style'     => $btn['style']     ?? null, // 'primary' | 'danger'
            ]);
        }, $buttons);

        return ['type' => 'actions', 'elements' => $elements];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the active channel configuration, honouring master + channel switches.
     * Returns null when sending should be skipped.
     *
     * @return array{bot_username:string,channel:string,webhook_url:string,icon_url:string}|null
     */
    private function resolveChannelConfig(): ?array
    {
        // Master switch
        if (!$this->slackConfig->isEnabled()) {
            return null;
        }

        // Webhook override bypasses all per-channel config
        if ($this->webhookOverride !== null) {
            return [
                'bot_username' => '',
                'channel'      => '',
                'webhook_url'  => $this->webhookOverride,
                'icon_url'     => '',
            ];
        }

        $cfg = $this->slackConfig->channel($this->activeChannel);

        if (empty($cfg['enabled']) || empty($cfg['webhook_url'])) {
            return null;
        }

        return $cfg;
    }

    /**
     * Build the final JSON payload for the Incoming Webhook endpoint.
     *
     * @param  array       $cfg     Resolved channel config.
     * @param  array       $blocks  Block Kit blocks.
     * @param  string|null $fallback Plain-text fallback.
     * @return array
     */
    private function buildPayload(array $cfg, array $blocks, ?string $fallback): array
    {
        return array_filter([
            'username' => $cfg['bot_username'] ?: null,
            'channel'  => $cfg['channel']      ?: null,
            'icon_url' => $cfg['icon_url']     ?: null,
            'text'     => $fallback            ?: null, // notification / search fallback
            'blocks'   => $blocks,
        ]);
    }

    /**
     * POST the payload to the webhook URL.
     *
     * @param  string $webhookUrl
     * @param  array  $payload
     * @return bool
     */
    private function dispatch(string $webhookUrl, array $payload): bool
    {
        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->post($webhookUrl, $json);

            $status = $this->curl->getStatus();

            if ($status !== 200) {
                $this->logger->warning(
                    '[CarpetAvenue\Core\Helper\Slack] Webhook responded with HTTP ' . $status,
                    ['webhook' => $webhookUrl, 'body' => $this->curl->getBody()]
                );
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(
                '[CarpetAvenue\Core\Helper\Slack] Failed to send Slack message: ' . $e->getMessage(),
                ['exception' => $e]
            );
            return false;
        }
    }
}

