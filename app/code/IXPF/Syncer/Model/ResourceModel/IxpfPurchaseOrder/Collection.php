<?php

namespace IXPF\Syncer\Model\ResourceModel\IxpfPurchaseOrder;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Zend_Db_Expr;

class Collection extends AbstractCollection
{

    protected function _construct()
    {
        $this->_init('IXPF\Syncer\Model\IxpfPurchaseOrder', 'IXPF\Syncer\Model\ResourceModel\IxpfPurchaseOrder');
    }

}
