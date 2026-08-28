<?php

namespace CarpetAvenue\Productify\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CaJpBlacklist extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ca_jp_blacklist', 'sku');
    }
}