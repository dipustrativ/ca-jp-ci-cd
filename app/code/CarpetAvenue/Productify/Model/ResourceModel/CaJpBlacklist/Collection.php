<?php

namespace CarpetAvenue\Productify\Model\ResourceModel\CaJpBlacklist;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{

    protected function _construct()
    {
        $this->_init(
            \CarpetAvenue\Productify\Model\CaJpBlacklist::class,
            \CarpetAvenue\Productify\Model\ResourceModel\CaJpBlacklist::class
        );
    }
}