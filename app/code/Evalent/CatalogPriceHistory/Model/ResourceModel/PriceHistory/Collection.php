<?php


namespace Evalent\CatalogPriceHistory\Model\ResourceModel\PriceHistory;


class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'row_id';

    protected function _construct()
    {
        $this->_init('Evalent\CatalogPriceHistory\Model\PriceHistory', 'Evalent\CatalogPriceHistory\Model\ResourceModel\PriceHistory');
    }
}