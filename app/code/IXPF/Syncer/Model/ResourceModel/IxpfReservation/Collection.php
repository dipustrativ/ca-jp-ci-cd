<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\ResourceModel\IxpfReservation;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(
            \IXPF\Syncer\Model\IxpfReservation::class,
            \IXPF\Syncer\Model\ResourceModel\IxpfReservation::class
        );
    }
}
