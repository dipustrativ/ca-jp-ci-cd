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

class SalesChannel implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'Physical stores', 'label' => __('Physical stores')],
            ['value' => 'Physical stores / Online', 'label' => __('Physical stores / Online')],
            ['value' => 'Online', 'label' => __('Online')],
        ];
    }
}
