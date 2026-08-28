<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Storefront catalog sales mode: B2C (normal Add to Cart) vs B2B
 * (Add to Cart hidden, "Become a Reseller" call-to-action shown).
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Model\Source;

use CarpetAvenue\B2B\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

class CatalogSalesMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::MODE_B2C, 'label' => __('B2C — Add to Cart')],
            ['value' => Config::MODE_B2B, 'label' => __('B2B — Become a Reseller')],
        ];
    }
}
