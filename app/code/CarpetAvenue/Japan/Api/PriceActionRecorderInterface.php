<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Shared write surface for carpetavenue_japan_price_action_log.
 *
 * Every Japan price rule records through this — special price guard today, any
 * future `price` or `pricing` rule tomorrow. Rules are kept apart by
 * `rule_code` + `attribute_code`, so two rules can act on the same product
 * without their histories mixing.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Japan
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Api;

use CarpetAvenue\Japan\Model\PriceAction\Entry;

interface PriceActionRecorderInterface
{
    /**
     * Append audit rows. One statement per 500 rows.
     *
     * @param Entry[] $entries
     * @return int Rows written
     */
    public function logMany(array $entries, string $runId, bool $dryRun = false): int;

    /**
     * Delete log rows older than the retention window. 0 days keeps everything.
     *
     * @param string|null $ruleCode Limit the prune to one rule; null prunes all
     */
    public function prune(int $days, ?string $ruleCode = null): int;
}
