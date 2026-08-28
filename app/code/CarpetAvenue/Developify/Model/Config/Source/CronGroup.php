<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Developify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Developify\Model\Config\Source;

use Magento\Cron\Model\ConfigInterface;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Cron groups declared by the installation — the Cron Jobs grid's group filter.
 */
class CronGroup implements OptionSourceInterface
{
    /**
     * Constructor
     *
     * @param ConfigInterface $cronConfig
     */
    public function __construct(private ConfigInterface $cronConfig)
    {
    }

    /**
     * Options for a select filter
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        $groups = array_keys($this->cronConfig->getJobs());
        sort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        $options = [];
        foreach ($groups as $group) {
            $options[] = ['value' => $group, 'label' => $group];
        }

        return $options;
    }
}
