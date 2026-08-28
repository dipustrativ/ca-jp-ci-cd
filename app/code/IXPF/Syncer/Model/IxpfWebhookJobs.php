<?php

namespace IXPF\Syncer\Model;

use \Magento\Framework\Model\AbstractModel;

class IxpfWebhookJobs extends AbstractModel
{
    const TABLE = 'ixpf_webhook_jobs';
    const ENTITY = 'entity_id';

    public function _construct()
    {
        $this->_init("IXPF\Syncer\Model\ResourceModel\IxpfWebhookJobs");
    }
}
