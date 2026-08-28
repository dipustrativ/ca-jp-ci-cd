<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class IxpfPurchaseOrder extends AbstractDb
{
    const TABLE = 'ixpf_purchase_orders';
    const ENTITY = 'entity_id';

    public function _construct()
    {
        $this->_init(self::TABLE, self::ENTITY);
    }
}