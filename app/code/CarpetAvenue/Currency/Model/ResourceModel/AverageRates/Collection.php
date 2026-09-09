<?php

namespace CarpetAvenue\Currency\Model\ResourceModel\AverageRates;


class Collection extends \Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult // \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'id';
    public function _construct()
    {
        $this->_init(
            \CarpetAvenue\Currency\Model\AverageRates::class,
            \CarpetAvenue\Currency\Model\ResourceModel\AverageRates::class
        );
    }
}