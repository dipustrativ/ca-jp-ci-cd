<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Rules that write to the shared Japan price tables. Add a row here when a new
 * rule starts recording, so the admin grid can filter by it.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Japan
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Model\Source\PriceAction;

use CarpetAvenue\Japan\Model\PriceAction\Entry;
use Magento\Framework\Data\OptionSourceInterface;

class RuleCode implements OptionSourceInterface
{
    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Entry::RULE_SPECIAL_PRICE_GUARD, 'label' => __('Special price guard')],
        ];
    }
}
