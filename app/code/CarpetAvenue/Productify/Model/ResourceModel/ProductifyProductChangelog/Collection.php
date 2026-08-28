<?php

namespace CarpetAvenue\Productify\Model\ResourceModel\ProductifyProductChangelog;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{

    protected function _construct()
    {
        $this->_init(
            \CarpetAvenue\Productify\Model\ProductifyProductChangelog::class,
            \CarpetAvenue\Productify\Model\ResourceModel\ProductifyProductChangelog::class
        );
    }
}