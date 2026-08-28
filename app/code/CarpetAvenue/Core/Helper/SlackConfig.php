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
use Magento\Store\Model\ScopeInterface;

/**
 * Slack Configuration Helper
 *
 * Reads all Slack-related system-config values stored under
 * carpetavenue_notification/slack_communication/*.
 *
 * @see docs/slack-notification.md  Full config path reference and usage examples.
 */
class SlackConfig extends AbstractHelper
{
    // -------------------------------------------------------------------------
    // Config path constants
    // -------------------------------------------------------------------------

    public const XML_PATH_BASE              = 'carpetavenue_notification/slack_communication/';

    // Master switch
    public const XML_PATH_MASTER_ENABLED    = self::XML_PATH_BASE . 'enabled';

    // Per-channel sub-paths  (replace {channel} with 'default' or 'watcher')
    public const XML_PATH_CHANNEL_ENABLED     = self::XML_PATH_BASE . '{channel}/enabled';
    public const XML_PATH_CHANNEL_BOT         = self::XML_PATH_BASE . '{channel}/bot_username';
    public const XML_PATH_CHANNEL_NAME        = self::XML_PATH_BASE . '{channel}/channel';
    public const XML_PATH_CHANNEL_WEBHOOK     = self::XML_PATH_BASE . '{channel}/webhook_url';
    public const XML_PATH_CHANNEL_ICON        = self::XML_PATH_BASE . '{channel}/icon_url';

    // Supported channel identifiers
    public const CHANNEL_DEFAULT  = 'default';
    public const CHANNEL_WATCHER  = 'watcher';

    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Is the master Slack integration switch on?
     */
    public function isEnabled(?string $scopeType = ScopeInterface::SCOPE_STORE, mixed $scopeCode = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_MASTER_ENABLED, $scopeType, $scopeCode);
    }

    /**
     * Return all config values for a channel as an associative array.
     *
     * @param  string $channelId  One of self::CHANNEL_* constants.
     * @return array{enabled:bool, bot_username:string, channel:string, webhook_url:string, icon_url:string}
     */
    public function channel(
        string $channelId = self::CHANNEL_DEFAULT,
        string $scopeType = ScopeInterface::SCOPE_STORE,
        mixed  $scopeCode = null
    ): array {
        $replace = static fn(string $path) => str_replace('{channel}', $channelId, $path);

        return [
            'enabled'      => $this->scopeConfig->isSetFlag($replace(self::XML_PATH_CHANNEL_ENABLED), $scopeType, $scopeCode),
            'bot_username' => (string) $this->scopeConfig->getValue($replace(self::XML_PATH_CHANNEL_BOT), $scopeType, $scopeCode),
            'channel'      => (string) $this->scopeConfig->getValue($replace(self::XML_PATH_CHANNEL_NAME), $scopeType, $scopeCode),
            'webhook_url'  => (string) $this->scopeConfig->getValue($replace(self::XML_PATH_CHANNEL_WEBHOOK), $scopeType, $scopeCode),
            'icon_url'     => (string) $this->scopeConfig->getValue($replace(self::XML_PATH_CHANNEL_ICON), $scopeType, $scopeCode),
        ];
    }

    /**
     * Shorthand: config for the Default channel.
     */
    public function defaultChannel(string $scopeType = ScopeInterface::SCOPE_STORE, mixed $scopeCode = null): array
    {
        return $this->channel(self::CHANNEL_DEFAULT, $scopeType, $scopeCode);
    }

    /**
     * Shorthand: config for the Watcher channel.
     */
    public function watcherChannel(string $scopeType = ScopeInterface::SCOPE_STORE, mixed $scopeCode = null): array
    {
        return $this->channel(self::CHANNEL_WATCHER, $scopeType, $scopeCode);
    }
}

