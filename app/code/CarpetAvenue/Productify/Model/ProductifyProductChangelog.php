<?php

namespace CarpetAvenue\Productify\Model;

use Magento\Framework\Model\AbstractModel;

class ProductifyProductChangelog extends AbstractModel
{

    protected function _construct(): void
    {
        $this->_init(ResourceModel\ProductifyProductChangelog::class);
    }

}