<?php

namespace CarpetAvenue\ReservationsFix\Model\ResourceModel;

class InventoryMove extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb{
    protected function _construct()
    {
        $this->_init('carpet_inventory_move', 'id');
    }
}