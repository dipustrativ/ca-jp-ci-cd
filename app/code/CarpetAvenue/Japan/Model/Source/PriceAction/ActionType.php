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

class ActionType implements OptionSourceInterface
{
    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Entry::ACTION_RAISE, 'label' => __('Raised to minimum')],
            ['value' => Entry::ACTION_CLEAR, 'label' => __('Cleared — no valid discount')],
            ['value' => Entry::ACTION_SKIP, 'label' => __('Skipped')],
            ['value' => Entry::ACTION_ERROR, 'label' => __('Error')],
        ];
    }
}
