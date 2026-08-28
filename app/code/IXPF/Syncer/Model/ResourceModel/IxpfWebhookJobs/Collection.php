<?php

namespace IXPF\Syncer\Model\ResourceModel\IxpfWebhookJobs;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{

    protected function _construct()
    {
        $this->_init('IXPF\Syncer\Model\IxpfWebhookJobs', 'IXPF\Syncer\Model\ResourceModel\IxpfWebhookJobs');
    }
}
