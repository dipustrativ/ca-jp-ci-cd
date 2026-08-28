<?php

declare(strict_types=1);

namespace Evalent\Banner\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Gutters implements OptionSourceInterface
{

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' => '<label>'), ...)
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'default', 'label' => __('Default')],
            ['value' => 'none', 'label' => __('None')],
            ['value' => 'small', 'label' => __('Small')],
            ['value' => 'medium', 'label' => __('Medium')],
            ['value' => 'large', 'label' => __('Large')],
            ['value' => 'xlarge', 'label' => __('XL')],
            ['value' => 'xxlarge', 'label' => __('XXL')]
        ];
    }
}
