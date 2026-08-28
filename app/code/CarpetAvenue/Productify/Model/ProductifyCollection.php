<?php

namespace CarpetAvenue\Productify\Model;

use Magento\Framework\Model\AbstractModel;
class ProductifyCollection extends AbstractModel
{

    protected function _construct(): void
    {
        $this->_init(ResourceModel\ProductifyCollection::class);
    }

}