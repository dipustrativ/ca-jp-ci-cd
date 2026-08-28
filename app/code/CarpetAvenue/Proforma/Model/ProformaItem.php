<?php

namespace CarpetAvenue\Proforma\Model;

class ProformaItem extends \Magento\Framework\Model\AbstractModel{
    public function _construct()
    {
        $this->_init(\CarpetAvenue\Proforma\Model\ResourceModel\ProformaItem::class);
    }
}