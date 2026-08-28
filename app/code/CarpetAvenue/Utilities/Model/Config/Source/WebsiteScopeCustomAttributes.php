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

class WebsiteScopeCustomAttributes implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        $attributes = [
            'cost' => 'Cost',
            'country_of_manufacture' => 'Country of Manufacture',
            'height2' => 'Length',
            'jpy_lowest_sale_price' => 'Lowest Sale Price JPY',
            'msrp' => 'Minimum Advertised Price',
            'msrp_display_actual_price_type' => 'Display Actual Price',
            'news_from_date' => 'Set Product as New from Date',
            'news_to_date' => 'Set Product as New to Date',
            'pricing' => '% Sale',
            'random' => 'Random Sorting',
            'retail_price' => 'retail_price',
            'special_from_date' => 'Special Price From Date',
            'special_to_date' => 'Special Price To Date',
            'sqm_buying_price' => 'SQM buying price',
            'sqm_selling_price' => 'SQM selling price',
            'tax_class_id' => 'Tax Class',
            'tier_price' => 'Tier Price',
            'width2' => 'Width',
        ];
        $options = [];
        foreach ($attributes as $code => $label) {
            $options[] = [
                'value' => $code,
                'label' => $label,
            ];
        }

        return $options;
    }
}