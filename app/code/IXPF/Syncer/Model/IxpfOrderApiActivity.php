<?php

namespace IXPF\Syncer\Model;

use Magento\Framework\Model\AbstractModel;

class IxpfOrderApiActivity extends AbstractModel
{
    const TABLE = 'ixpf_order_api_activity';
    const ENTITY = 'entity_id';

    const STATUS_SUCCESS = 'success';
    const STATUS_ERROR = 'error';

    public function _construct()
    {
        $this->_init("IXPF\Syncer\Model\ResourceModel\IxpfOrderApiActivity");
    }
}
