<?php

declare(strict_types=1);

namespace Evalent\Banner\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ButtonSize implements OptionSourceInterface
{

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' => '<label>'), ...)
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'large', 'label' => "Large (" . __('35px') . ")"],
            ['value' => 'medium', 'label' => "Medium (" . __('30px') . ")"],
            ['value' => 'small', 'label' => "Small (" . __('25px') . ")"],
            ['value' => 'xs', 'label' => "XSmall (" . __('20px') . ")"],
            ['value' => 'xl', 'label' => "XL (" . __('40px') . ")"],
            ['value' => 'xxl', 'label' => "XXL (" . __('45px') . ")"],
            ['value' => 'xxxl', 'label' => "XXXL (" . __('50px') . ")"]
        ];
    }
}
