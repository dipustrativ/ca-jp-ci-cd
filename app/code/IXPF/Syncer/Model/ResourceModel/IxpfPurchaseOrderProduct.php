<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class IxpfPurchaseOrderProduct extends AbstractDb
{
    const TABLE = 'ixpf_purchase_order_items';
    const ENTITY = 'entity_id';

    public function _construct()
    {
        $this->_init(self::TABLE, self::ENTITY);
    }
}