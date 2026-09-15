<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Japan
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Model\SpecialPriceGuard;

use CarpetAvenue\Japan\Model\PriceAction\Entry;

class RunResult
{
    /**
     * @param Entry[] $changes Products whose price changed, for the CLI preview
     */
    public function __construct(
        public readonly string $runId,
        public readonly bool $dryRun,
        public readonly int $scanned = 0,
        public readonly int $raised = 0,
        public readonly int $cleared = 0,
        public readonly int $skipped = 0,
        public readonly int $errors = 0,
        public readonly int $catalogCount = 0,
        public readonly bool $aborted = false,
        public readonly ?string $abortReason = null,
        public readonly array $changes = []
    ) {
    }

    public function summary(): string
    {
        if ($this->aborted) {
            return sprintf('[%s] aborted: %s', $this->runId, (string)$this->abortReason);
        }

        return sprintf(
            '[%s]%s scanned %d, raised %d, cleared %d, skipped %d, errors %d',
            $this->runId,
            $this->dryRun ? ' DRY RUN' : '',
            $this->scanned,
            $this->raised,
            $this->cleared,
            $this->skipped,
            $this->errors
        );
    }
}
