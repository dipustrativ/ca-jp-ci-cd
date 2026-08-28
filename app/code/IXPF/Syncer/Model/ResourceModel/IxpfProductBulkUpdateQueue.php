<?php
namespace IXPF\Syncer\Model\ResourceModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
class IxpfProductBulkUpdateQueue extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('ixpf_product_bulk_update_queue', 'entity_id');
    }
}

