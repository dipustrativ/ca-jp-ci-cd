<?php

namespace IXPF\Syncer\Model\ResourceModel;

use \Magento\Framework\Model\ResourceModel\Db\AbstractDb;
class IxpfWebhookJobs extends AbstractDb
{
    const TABLE = 'ixpf_webhook_jobs';
    const ENTITY = 'entity_id';

    public function _construct()
    {
        $this->_init(self::TABLE, self::ENTITY);
    }
}
