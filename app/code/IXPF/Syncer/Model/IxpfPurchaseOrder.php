<?php

namespace IXPF\Syncer\Model;

use Magento\Framework\Model\AbstractModel;

class IxpfPurchaseOrder extends AbstractModel
{
    const TABLE = 'ixpf_purchase_orders';
    const ENTITY = 'entity_id';

    public function _construct()
    {
        $this->_init("IXPF\Syncer\Model\ResourceModel\IxpfPurchaseOrder");
    }
}
