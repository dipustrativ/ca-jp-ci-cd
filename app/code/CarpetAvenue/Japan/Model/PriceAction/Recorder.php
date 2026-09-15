<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Japan
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Model\PriceAction;

use CarpetAvenue\Japan\Api\PriceActionRecorderInterface;
use CarpetAvenue\Japan\Model\ResourceModel\PriceActionLog;

class Recorder implements PriceActionRecorderInterface
{
    public function __construct(
        private readonly PriceActionLog $logResource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function logMany(array $entries, string $runId, bool $dryRun = false): int
    {
        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = $entry->toRow($runId, $dryRun);
        }

        return $this->logResource->addMany($rows);
    }

    /**
     * @inheritDoc
     */
    public function prune(int $days, ?string $ruleCode = null): int
    {
        return $this->logResource->prune($days, $ruleCode);
    }
}
