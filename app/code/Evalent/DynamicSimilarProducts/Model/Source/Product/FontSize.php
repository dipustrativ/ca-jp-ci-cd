<?php

declare(strict_types=1);

namespace Evalent\DynamicSimilarProducts\Model\Source\Product;

use Magento\Framework\Data\OptionSourceInterface;

class FontSize implements OptionSourceInterface
{
    /**
     * Return array of options in order to change font size
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => '', 'label' => __('-- Select an Option --')],
            ['value' => '14px', 'label' => __('14px')],
            ['value' => '15px', 'label' => __('15px')],
            ['value' => '16px', 'label' => __('16px')],
            ['value' => '17px', 'label' => __('17px')],
            ['value' => '18px', 'label' => __('18px')],
            ['value' => '19px', 'label' => __('19px')],
            ['value' => '20px', 'label' => __('20px')],
            ['value' => '21px', 'label' => __('21px')],
            ['value' => '22px', 'label' => __('22px')],
            ['value' => '23px', 'label' => __('23px')],
            ['value' => '24px', 'label' => __('24px')],
            ['value' => '25px', 'label' => __('25px')]
        ];
    }
}
