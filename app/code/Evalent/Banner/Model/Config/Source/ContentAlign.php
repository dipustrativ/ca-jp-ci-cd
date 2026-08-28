<?php

declare(strict_types=1);

namespace Evalent\Banner\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ContentAlign implements OptionSourceInterface
{

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' => '<label>'), ...)
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'top-left', 'label' => __('Top Left')],
            ['value' => 'top-center', 'label' => __('Top Center')],
            ['value' => 'top-right', 'label' => __('Top Right')],
            ['value' => 'center-left', 'label' => __('Center Left')],
            ['value' => 'center-center', 'label' => __('Center')],
            ['value' => 'center-right', 'label' => __('Center Right')],
            ['value' => 'bottom-left', 'label' => __('Bottom left')],
            ['value' => 'bottom-center', 'label' => __('Bottom Center')],
            ['value' => 'bottom-right', 'label' => __('Bottom Right')],
        ];
    }
}