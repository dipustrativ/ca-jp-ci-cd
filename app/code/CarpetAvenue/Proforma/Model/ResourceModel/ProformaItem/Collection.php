<?php
namespace CarpetAvenue\Proforma\Model\ResourceModel\ProformaItem;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'entity_id';
    public function _construct()
    {
        $this->_init(
            \CarpetAvenue\Proforma\Model\ProformaItem::class,
            \CarpetAvenue\Proforma\Model\ResourceModel\ProformaItem::class
        );
    }
}