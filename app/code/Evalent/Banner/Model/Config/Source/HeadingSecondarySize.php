<?php

declare(strict_types=1);

namespace Evalent\Banner\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class HeadingSecondarySize implements OptionSourceInterface
{

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' => '<label>'), ...)
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'large', 'label' => "Large (" . __('40px') . ")"],
            ['value' => 'medium', 'label' => 'Medium (' . __('30px') . ")"],
            ['value' => 'small', 'label' => 'Small (' . __('25px') . ")"],
            ['value' => 'xs', 'label' => 'XSmall (' . __('28px') . ")"],
            ['value' => 'xl', 'label' => "XL (" . __('50px') . ")"],
            ['value' => 'xxl', 'label' => "XXL (" . __('60px') . ")"],
            ['value' => 'xxxl', 'label' => "XXXL (" . __('70px') . ")"]
        ];
    }
}
