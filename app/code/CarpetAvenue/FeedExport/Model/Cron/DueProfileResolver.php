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

namespace CarpetAvenue\FeedExport\Model\Cron;

use CarpetAvenue\FeedExport\Api\Data\ProfileInterface;
use CarpetAvenue\FeedExport\Model\ResourceModel\Profile\CollectionFactory;
use Magento\Cron\Model\ScheduleFactory;
use Magento\Framework\Exception\CronException;
use Magento\Framework\FlagManager;
use Psr\Log\LoggerInterface;

/**
 * Finds profiles whose cron expression matched any minute since the previous tick.
 * Catch-up matters because the scheduler job is skipped by Magento while a long export still runs.
 */
class DueProfileResolver
{
    private const FLAG_LAST_TICK = 'carpetavenue_feedexport_last_tick';

    /** Never look back further than this after downtime. */
    private const MAX_CATCH_UP_MINUTES = 120;

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ScheduleFactory $scheduleFactory,
        private readonly FlagManager $flagManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return ProfileInterface[]
     */
    public function getDueProfiles(int $now): array
    {
        $currentMinute = $now - ($now % 60);
        $lastTick = (int)$this->flagManager->getFlagData(self::FLAG_LAST_TICK);
        if ($lastTick === $currentMinute) {
            return []; // This minute was already handled.
        }
        if (!$lastTick || $lastTick > $currentMinute) {
            $lastTick = $currentMinute - 60;
        }
        $lastTick = max($lastTick, $currentMinute - self::MAX_CATCH_UP_MINUTES * 60);
        $this->flagManager->saveFlag(self::FLAG_LAST_TICK, $currentMinute);

        $collection = $this->collectionFactory->create()
            ->addFieldToFilter(ProfileInterface::ENABLED, 1)
            ->addFieldToFilter(ProfileInterface::CRON_ENABLED, 1);

        $due = [];
        /** @var ProfileInterface $profile */
        foreach ($collection as $profile) {
            if ($this->isDue($profile, $lastTick + 60, $currentMinute)) {
                $due[] = $profile;
            }
        }
        return $due;
    }

    private function isDue(ProfileInterface $profile, int $from, int $to): bool
    {
        foreach ($profile->getCronExpressions() as $expression) {
            try {
                $schedule = $this->scheduleFactory->create()->setCronExpr($expression);
            } catch (CronException $e) {
                $this->logger->warning(sprintf(
                    '[FeedExport] Profile %d has invalid cron expression "%s"',
                    $profile->getProfileId(),
                    $expression
                ));
                continue;
            }
            for ($minute = $from; $minute <= $to; $minute += 60) {
                if ($schedule->setScheduledAt($minute)->trySchedule()) {
                    return true;
                }
            }
        }
        return false;
    }
}
