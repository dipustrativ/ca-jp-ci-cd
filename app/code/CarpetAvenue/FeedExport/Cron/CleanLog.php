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

namespace CarpetAvenue\FeedExport\Cron;

use CarpetAvenue\FeedExport\Model\Config;
use CarpetAvenue\FeedExport\Model\ResourceModel\Log as LogResource;

class CleanLog
{
    public function __construct(
        private readonly Config $config,
        private readonly LogResource $logResource
    ) {
    }

    public function execute(): void
    {
        $days = $this->config->getLogLifetimeDays();
        if ($days > 0) {
            $this->logResource->deleteOlderThan($days);
        }
    }
}
