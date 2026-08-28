<?php

declare(strict_types=1);

namespace Evalent\Banner\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class HeadingSize implements OptionSourceInterface
{

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' => '<label>'), ...)
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'large', 'label' => "Large (" . __('60px') . " / CTA 50px)"],
            ['value' => 'extra_large', 'label' => "Extra Large (" . __('122px') . " )"],
            ['value' => 'medium', 'label' => 'Medium (' . __('50px') . " / CTA 40px)"],
            ['value' => 'small', 'label' => 'Small (' . __('40px') . " / CTA 30px)"],
            ['value' => 'xs', 'label' => 'XSmall (' . __('18px') . " / CTA 25px)"],
            ['value' => 'xl', 'label' => "XL (" . __('70px') . " / CTA 55px)"],
            ['value' => 'xxl', 'label' => "XXL (" . __('80px') . " / CTA 60px)"],
            ['value' => 'xxxl', 'label' => "XXXL (" . __('90px') . " / CTA 65px)"]
        ];
    }
}
