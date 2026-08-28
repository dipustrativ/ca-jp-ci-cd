<?php

namespace CarpetAvenue\Proforma\Model\ResourceModel;

class ProformaItem extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb{
    protected function _construct()
    {
        $this->_init('sales_proforma_item', 'entity_id');
    }
}