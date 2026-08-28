<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Blacklist extends AbstractDb
{
    const TABLE = 'carpetavenue_order_black_list';
    const ENTITY = 'entity_id';

    public function _construct()
    {
        $this->_init(self::TABLE, self::ENTITY);
    }
}