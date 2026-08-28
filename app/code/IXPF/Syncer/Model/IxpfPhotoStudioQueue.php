<?php

namespace IXPF\Syncer\Model;

use Magento\Framework\Model\AbstractModel;

class IxpfPhotoStudioQueue extends AbstractModel
{

    public function _construct(): void
    {
        $this->_init("IXPF\Syncer\Model\ResourceModel\IxpfPhotoStudioQueue");
    }
}