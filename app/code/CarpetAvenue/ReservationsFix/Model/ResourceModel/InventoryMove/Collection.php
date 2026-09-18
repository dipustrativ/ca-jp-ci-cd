<?php

namespace CarpetAvenue\ReservationsFix\Model\ResourceModel\InventoryMove;


class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'id';
    public function _construct()
    {
        $this->_init(
            \CarpetAvenue\ReservationsFix\Model\InventoryMove::class,
            \CarpetAvenue\ReservationsFix\Model\ResourceModel\InventoryMove::class
        );
    }
}