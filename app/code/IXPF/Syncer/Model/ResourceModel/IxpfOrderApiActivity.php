<?php

namespace IXPF\Syncer\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class IxpfOrderApiActivity extends AbstractDb
{
    const TABLE = 'ixpf_order_api_activity';
    const ENTITY = 'entity_id';

    public function _construct()
    {
        $this->_init(self::TABLE, self::ENTITY);
    }
}
