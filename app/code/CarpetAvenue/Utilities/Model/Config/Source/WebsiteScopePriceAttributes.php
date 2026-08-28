<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class WebsiteScopePriceAttributes implements OptionSourceInterface
{
    /**
     * Fixed list of website-scope price attributes.
     */
    private const FIXED_ATTRIBUTES = [
        'price'         => 'Price',
        'special_price' => 'Special Price',
        'otto_price'    => 'Otto Price',
    ];

    public function toOptionArray(): array
    {
        $options = [];
        foreach (self::FIXED_ATTRIBUTES as $code => $label) {
            $options[] = [
                'value' => $code,
                'label' => $code . ' (' . $label . ')',
            ];
        }

        return $options;
    }
}