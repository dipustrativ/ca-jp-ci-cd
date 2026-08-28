<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\Block\Adminhtml\System\Config\JpCurrencyConvert;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

class Thresholds extends AbstractFieldArray
{
    /**
     * @inheritdoc
     */
    protected function _prepareToRender(): void
    {
        $this->addColumn(
            'threshold_title',
            ['label' => __('Threshold Title'), 'renderer' => false]
        );
        $this->addColumn(
            'threshold_min_value',
            ['label' => __('Threshold Min Value'), 'renderer' => false]
        );
        $this->addColumn(
            'threshold_max_value',
            ['label' => __('Threshold Max Value'), 'renderer' => false]
        );
        $this->addColumn(
            'number_of_zeroes',
            ['label' => __('Number of zeroes'), 'renderer' => false]
        );
        $this->_addAfter       = false;
        $this->_addButtonLabel = __('Add');
    }


}