<?php

declare(strict_types=1);

namespace Evalent\Banner\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class DescriptionSize implements OptionSourceInterface
{

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' => '<label>'), ...)
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'large', 'label' => "Large (" . __('40px') . " / CTA 35px)"],
            ['value' => 'medium', 'label' => 'Medium (' . __('30px') . " / CTA 28px)"],
            ['value' => 'small', 'label' => 'Small (' . __('25px') . " / CTA 20px)"],
            ['value' => 'xs', 'label' => 'XSmall (' . __('20px') . " / CTA 18px)"],
            ['value' => 'xl', 'label' => "XL (" . __('50px') . " / CTA 38px)"],
            ['value' => 'xxl', 'label' => "XXL (" . __('60px') . " / CTA 45px)"],
            ['value' => 'xxxl', 'label' => "XXXL (" . __('70px') . " / CTA 50px)"]
        ];
    }
}
