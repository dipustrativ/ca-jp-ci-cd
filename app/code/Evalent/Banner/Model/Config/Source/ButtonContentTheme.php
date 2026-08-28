<?php

declare(strict_types=1);

namespace Evalent\Banner\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ButtonContentTheme implements OptionSourceInterface
{

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' => '<label>'), ...)
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'light', 'label' => __('Light')],
            ['value' => 'dark', 'label' => __('Dark')],
            ['value' => 'gold', 'label' => __('Gold')],
            ['value' => 'silver', 'label' => __('Silver')],
            ['value' => 'custom', 'label' => __('Custom')]
        ];
    }
}
