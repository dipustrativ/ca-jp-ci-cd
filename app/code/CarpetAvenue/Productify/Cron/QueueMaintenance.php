<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Cron;

use CarpetAvenue\Productify\Helper\Logger;
use CarpetAvenue\Productify\Service\Core\QueueManagement;

class QueueMaintenance
{
    public function __construct(
        private readonly QueueManagement $queueManagement,
        protected Logger $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $stuckCount = $this->queueManagement->markStuckAsFailed(60);
            if ($stuckCount > 0) {
                $this->logger->warning("QueueMaintenance: Marked $stuckCount stuck jobs as failed.");
            }

            $retryCount = $this->queueManagement->retryAllFailed(2);
            if ($retryCount > 0) {
                $this->logger->info("QueueMaintenance: Retried $retryCount failed jobs.");
            }

            if ($stuckCount === 0 && $retryCount === 0) {
                $this->logger->debug("QueueMaintenance: Nothing to clean.");
            }
        } catch (\Throwable $exception) {
            $this->logger->error("QueueMaintenance failed: " . $exception->getMessage());
        }
    }
}
