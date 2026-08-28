<?php
namespace IXPF\Syncer\Model\ResourceModel\IxpfProductBulkUpdateQueue;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(
            \IXPF\Syncer\Model\IxpfProductBulkUpdateQueue::class,
            \IXPF\Syncer\Model\ResourceModel\IxpfProductBulkUpdateQueue::class
        );
    }
}

