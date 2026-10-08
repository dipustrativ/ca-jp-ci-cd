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

use CarpetAvenue\FeedExport\Api\FeedExporterInterface;
use CarpetAvenue\FeedExport\Model\Config;
use CarpetAvenue\FeedExport\Model\Cron\DueProfileResolver;
use Psr\Log\LoggerInterface;

/**
 * Runs every minute; exports each profile whose own cron expression is due.
 */
class RunScheduledProfiles
{
    public function __construct(
        private readonly Config $config,
        private readonly DueProfileResolver $dueProfileResolver,
        private readonly FeedExporterInterface $feedExporter,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isCronEnabled()) {
            return;
        }
        foreach ($this->dueProfileResolver->getDueProfiles(time()) as $profile) {
            try {
                $this->feedExporter->export((int)$profile->getProfileId(), FeedExporterInterface::TRIGGER_CRON);
            } catch (\Exception $e) {
                // Already written to the execution log; keep going with the other profiles.
                $this->logger->error('[FeedExport] Cron export failed: ' . $e->getMessage());
            }
        }
    }
}
