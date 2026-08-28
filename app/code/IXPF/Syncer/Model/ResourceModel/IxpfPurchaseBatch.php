<?php

namespace IXPF\Syncer\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class IxpfPurchaseBatch extends AbstractDb
{
    const TABLE = 'ixpf_purchases_batch';
    const ENTITY = 'entity_id';

    public function _construct()
    {
        $this->_init(self::TABLE, self::ENTITY);
    }
}