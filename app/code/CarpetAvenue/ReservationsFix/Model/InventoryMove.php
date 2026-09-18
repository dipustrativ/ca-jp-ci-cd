<?php

namespace CarpetAvenue\ReservationsFix\Model;

class InventoryMove extends \Magento\Framework\Model\AbstractModel{
    public function _construct()
    {
        $this->_init(\CarpetAvenue\ReservationsFix\Model\ResourceModel\InventoryMove::class);
    }

    public function getProcessed(){
        return (bool)$this->getData('processed');
    }
}