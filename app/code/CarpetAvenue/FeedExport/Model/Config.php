<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_FeedExport
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\FeedExport\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

class Config
{
    public const XML_PATH_CRON_ENABLED = 'carpetavenue_feedexport/general/cron_enabled';
    public const XML_PATH_BATCH_SIZE = 'carpetavenue_feedexport/general/batch_size';
    public const XML_PATH_LOG_LIFETIME = 'carpetavenue_feedexport/general/log_lifetime';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isCronEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CRON_ENABLED);
    }

    public function getBatchSize(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_PATH_BATCH_SIZE));
    }

    public function getLogLifetimeDays(): int
    {
        return max(0, (int)$this->scopeConfig->getValue(self::XML_PATH_LOG_LIFETIME));
    }
}
