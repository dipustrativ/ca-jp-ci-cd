<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ApplicationStatus implements OptionSourceInterface
{
    public const STATUS_NEW = 'new';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::STATUS_NEW, 'label' => __('New')],
            ['value' => self::STATUS_CONTACTED, 'label' => __('Contacted')],
            ['value' => self::STATUS_APPROVED, 'label' => __('Approved')],
            ['value' => self::STATUS_REJECTED, 'label' => __('Rejected')],
        ];
    }
}
