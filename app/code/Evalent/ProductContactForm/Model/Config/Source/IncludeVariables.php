<?php


namespace Evalent\ProductContactForm\Model\Config\Source;

class IncludeVariables implements \Magento\Framework\Option\ArrayInterface
{

    public function toOptionArray()
    {
        return [['value' => '', 'label' => __('')]];
    }

    public function toArray()
    {
        return ['' => __('')];
    }
}
