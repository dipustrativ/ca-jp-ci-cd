<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\ResourceModel\IxpfPhotoStudioQueue;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{

    protected function _construct(): void
    {
        $this->_init('IXPF\Syncer\Model\IxpfPhotoStudioQueue', 'IXPF\Syncer\Model\ResourceModel\IxpfPhotoStudioQueue');
    }

}