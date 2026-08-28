<?php

namespace CarpetAvenue\Developify\Model\Config\Source;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\OptionSourceInterface;

class Type implements OptionSourceInterface
{
    public function __construct(
        protected ResourceConnection $resourceConnection
    )
    {
    }

    public function toOptionArray(): array
    {
        return [
            ['value' => 'order_status', 'label' => __('Order Status')],
            ['value' => 'order_customer_group', 'label' => __('Order Customer Group')],
        ];
    }
}