<?php


namespace Evalent\CatalogPriceHistory\Model;


class PriceHistory extends \Magento\Framework\Model\AbstractModel
{
    public function _construct()
    {
        $this->_init(\Evalent\CatalogPriceHistory\Model\ResourceModel\PriceHistory::class);
    }
}