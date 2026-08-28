<?php

declare(strict_types=1);

namespace Evalent\Banner\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Margins implements OptionSourceInterface
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
            ['value' => 'thinest', 'label' => __('Thinest')],
            ['value' => 'thin', 'label' => __('Thin')],
            ['value' => 'medium', 'label' => __('Medium')],
            ['value' => 'wide', 'label' => __('Wide')],
            ['value' => 'xwide', 'label' => __('Extra Wide')]
        ];
    }
}