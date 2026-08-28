<?php

declare(strict_types=1);

namespace Evalent\MegaMenu\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class AdditionalData implements OptionSourceInterface
{

    /**
     * @inheritdoc
     */
    public function toOptionArray()
    {
        $options[] = ['label' => __('-----'), 'value' => ' '];
        $options[] = ['label' => __('Pull Right'), 'value' => 'right'];
        $options[] = ['label' => __('Green Text'), 'value' => 'green'];
        $options[] = ['label' => __('Red Text'), 'value' => 'red'];
        $options[] = ['label' => __('Carpient'), 'value' => 'carpient'];
        $options[] = ['label' => __('White Border'), 'value' => 'white_border'];
        return $options;
    }
}
