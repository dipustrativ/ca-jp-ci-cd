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

class BusinessType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'Retailer', 'label' => __('Retailer')],
            ['value' => 'Wholesale', 'label' => __('Wholesale')],
            ['value' => 'Retail Chain', 'label' => __('Retail Chain')],
        ];
    }
}
