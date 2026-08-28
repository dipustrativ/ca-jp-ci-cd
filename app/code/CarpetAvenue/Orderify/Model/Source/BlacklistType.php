<?php

namespace CarpetAvenue\Orderify\Model\Source;

use Magento\Framework\Option\ArrayInterface;

class BlacklistType implements ArrayInterface
{
    /**
     * Get options
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'email', 'label' => __('Email')],
            ['value' => 'address', 'label' => __('Address')],
            ['value' => 'ip', 'label' => __('IP')],
        ];
    }

}