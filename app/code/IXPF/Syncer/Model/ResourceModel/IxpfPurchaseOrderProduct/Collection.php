<?php

namespace IXPF\Syncer\Model\ResourceModel\IxpfPurchaseOrderProduct;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{

    protected function _construct()
    {
        $this->_init('IXPF\Syncer\Model\IxpfPurchaseOrderProduct', 'IXPF\Syncer\Model\ResourceModel\IxpfPurchaseOrderProduct');
    }
}
