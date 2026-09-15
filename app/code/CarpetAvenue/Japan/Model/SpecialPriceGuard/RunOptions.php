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

class RunOptions
{
    /**
     * @param bool     $dryRun Decide and log, but write nothing to the catalog
     * @param string[] $skus   Restrict the run to these SKUs
     * @param int      $limit  Stop after scanning this many products; 0 = no limit
     * @param bool     $force  Ignore the blast-radius cap and the master switch
     */
    public function __construct(
        public readonly bool $dryRun = false,
        public readonly array $skus = [],
        public readonly int $limit = 0,
        public readonly bool $force = false
    ) {
    }
}
