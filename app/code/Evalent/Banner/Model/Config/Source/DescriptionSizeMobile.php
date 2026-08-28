<?php

declare(strict_types=1);

namespace Evalent\Banner\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class DescriptionSizeMobile implements OptionSourceInterface
{

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' => '<label>'), ...)
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'large', 'label' => "Large (" . __('22px') . " / CTA 22px)"],
            ['value' => 'medium', 'label' => 'Medium (' . __('20px') . " / CTA 20px)"],
            ['value' => 'small', 'label' => 'Small (' . __('18px') . " / CTA 16px)"],
            ['value' => 'xs', 'label' => 'XSmall (' . __('15px') . " / CTA 14px)"],
            ['value' => 'xl', 'label' => "XL (" . __('25px') . " / CTA 28px)"],
            ['value' => 'xxl', 'label' => "XXL (" . __('28px') . " / CTA 32px)"],
            ['value' => 'xxxl', 'label' => "XXXL (" . __('30px') . " / CTA 36px)"]
        ];
    }
}
