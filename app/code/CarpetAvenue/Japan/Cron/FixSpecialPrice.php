<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Scheduled special-price guard run. Ported from the base store's
 * JPWebsiteRules cron, which read a `vw_jp_specialprice` database view; the
 * view is gone and the state lives in carpetavenue_japan_special_price_state.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Japan
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Cron;

use CarpetAvenue\Japan\Api\SpecialPriceGuardConfigInterface;
use CarpetAvenue\Japan\Model\SpecialPriceGuard\RunOptions;
use CarpetAvenue\Japan\Model\SpecialPriceGuard\Runner;
use Psr\Log\LoggerInterface;

class FixSpecialPrice
{
    public function __construct(
        private readonly SpecialPriceGuardConfigInterface $config,
        private readonly Runner $runner,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled() || !$this->config->isCronEnabled()) {
            return;
        }

        try {
            $this->runner->run(new RunOptions());
        } catch (\Exception $e) {
            $this->logger->error('[CarpetAvenue_Japan] special price guard cron failed: ' . $e->getMessage());
        }
    }
}
