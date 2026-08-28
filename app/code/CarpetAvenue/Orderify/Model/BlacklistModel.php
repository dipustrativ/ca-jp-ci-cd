<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Model;

use Magento\Framework\Model\AbstractModel;

class BlacklistModel extends AbstractModel
{

    public function _construct()
    {
        $this->_init("CarpetAvenue\Orderify\Model\ResourceModel\BlacklistModel");
    }
}