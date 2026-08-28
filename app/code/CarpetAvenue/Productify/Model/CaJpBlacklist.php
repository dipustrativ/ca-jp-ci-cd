<?php

namespace CarpetAvenue\Productify\Model;

use Magento\Framework\Model\AbstractModel;

class CaJpBlacklist extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(ResourceModel\CaJpBlacklist::class);
    }
}
