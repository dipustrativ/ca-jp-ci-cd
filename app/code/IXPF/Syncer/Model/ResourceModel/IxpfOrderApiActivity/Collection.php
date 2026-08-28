<?php

namespace IXPF\Syncer\Model\ResourceModel\IxpfOrderApiActivity;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init('IXPF\Syncer\Model\IxpfOrderApiActivity', 'IXPF\Syncer\Model\ResourceModel\IxpfOrderApiActivity');
    }
}
