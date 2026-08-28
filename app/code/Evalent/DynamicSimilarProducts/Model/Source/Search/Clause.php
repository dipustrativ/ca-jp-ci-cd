<?php

declare(strict_types=1);

namespace Evalent\DynamicSimilarProducts\Model\Source\Search;

use Magento\Framework\Data\OptionSourceInterface;

class Clause implements OptionSourceInterface
{
    /**
     * Return array of options in order to change font size
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'must', 'label' => __('must')],
            ['value' => 'should', 'label' => __('should')]
        ];
    }
}
