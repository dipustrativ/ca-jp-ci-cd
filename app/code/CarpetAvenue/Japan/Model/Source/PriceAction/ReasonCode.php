<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Japan
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Model\Source\PriceAction;

use CarpetAvenue\Japan\Model\PriceAction\Entry;
use Magento\Framework\Data\OptionSourceInterface;

class ReasonCode implements OptionSourceInterface
{
    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Entry::REASON_BELOW_MIN, 'label' => __('Below minimum')],
            ['value' => Entry::REASON_ABOVE_REGULAR_PRICE, 'label' => __('Minimum above regular price')],
            ['value' => Entry::REASON_NO_COST, 'label' => __('No cost')],
            ['value' => Entry::REASON_NO_SPECIAL_PRICE, 'label' => __('No special price')],
            ['value' => Entry::REASON_IN_RANGE, 'label' => __('Already above minimum')],
            ['value' => Entry::REASON_ERROR, 'label' => __('Error')],
        ];
    }
}
