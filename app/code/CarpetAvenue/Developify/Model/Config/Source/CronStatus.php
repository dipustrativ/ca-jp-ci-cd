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

use Magento\Cron\Model\Schedule;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * The five cron_schedule statuses, for the grid's status filter dropdown.
 */
class CronStatus implements OptionSourceInterface
{
    /**
     * Status value => label
     *
     * @return array
     */
    public function getLabels(): array
    {
        return [
            Schedule::STATUS_PENDING => __('Pending'),
            Schedule::STATUS_RUNNING => __('Running'),
            Schedule::STATUS_SUCCESS => __('Success'),
            Schedule::STATUS_MISSED => __('Missed'),
            Schedule::STATUS_ERROR => __('Error'),
        ];
    }

    /**
     * Options for a select filter
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->getLabels() as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }
}
