<?php

declare(strict_types=1);

namespace Evalent\Banner\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class HeadingSizeMobile implements OptionSourceInterface
{

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' => '<label>'), ...)
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'large', 'label' => "Large (" . __('38px') . " / CTA 36px)"],
            ['value' => 'medium', 'label' => 'Medium (' . __('30px') . " / CTA 28px)"],
            ['value' => 'small', 'label' => 'Small (' . __('25px') . " / CTA 22px)"],
            ['value' => 'xs', 'label' => 'XSmall (' . __('18px') . ") / CTA 18px"],
            ['value' => 'xl', 'label' => "XL (" . __('40px') . " / CTA 40px)"],
            ['value' => 'xxl', 'label' => "XXL (" . __('41px') . " / CTA 42px)"],
            ['value' => 'xxxl', 'label' => "XXXL (" . __('42px') . " / CTA 48px)"]
        ];
    }
}
