<?php

namespace IXPF\Syncer\Model\ResourceModel\IxpfPurchaseBatch;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection  extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init('IXPF\Syncer\Model\IxpfPurchaseBatch', 'IXPF\Syncer\Model\ResourceModel\IxpfPurchaseBatch');
    }
}