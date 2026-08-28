<?php

namespace IXPF\Syncer\Model;

use Magento\Framework\Model\AbstractModel;

class IxpfPurchaseOrderProduct extends AbstractModel
{
    const TABLE = 'ixpf_purchase_order_items';
    const ENTITY = 'entity_id';

    public function _construct()
    {
        $this->_init("IXPF\Syncer\Model\ResourceModel\IxpfPurchaseOrderProduct");
    }
}
