<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Model\ResourceModel\Blacklist;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection  extends AbstractCollection
{

    protected function _construct()
    {
        $this->_init('CarpetAvenue\Orderify\Model\BlacklistModel', 'CarpetAvenue\Orderify\Model\ResourceModel\Blacklist');
    }
}