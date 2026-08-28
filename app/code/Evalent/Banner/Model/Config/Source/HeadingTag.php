<?php

declare(strict_types=1);

namespace Evalent\Banner\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class HeadingTag implements OptionSourceInterface
{

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' => '<label>'), ...)
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'h1', 'label' => "H1 (" . __('H1') . ")"],
            ['value' => 'h2', 'label' => 'H2 (' . __('H2') . ")"],
            ['value' => 'h3', 'label' => 'H3 (' . __('H3') . ")"],
        ];
    }
}