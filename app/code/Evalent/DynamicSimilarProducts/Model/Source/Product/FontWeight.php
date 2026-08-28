<?php

declare(strict_types=1);

namespace Evalent\DynamicSimilarProducts\Model\Source\Product;

use Magento\Framework\Data\OptionSourceInterface;

class FontWeight implements OptionSourceInterface
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
            ['value' => 400, 'label' => __('Normal - 400')],
            ['value' => 500, 'label' => __('Medium - 500')],
            ['value' => 600, 'label' => __('Semibold - 600')],
            ['value' => 700, 'label' => __('Bold - 700')]
        ];
    }
}
