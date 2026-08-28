<?php
namespace IXPF\Syncer\Model;
use Magento\Framework\Model\AbstractModel;

class IxpfProductBulkUpdateQueue extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\IXPF\Syncer\Model\ResourceModel\IxpfProductBulkUpdateQueue::class);
    }
}
