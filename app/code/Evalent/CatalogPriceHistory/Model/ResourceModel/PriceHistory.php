<?php


namespace Evalent\CatalogPriceHistory\Model\ResourceModel;


class PriceHistory extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    public function __construct(\Magento\Framework\Model\ResourceModel\Db\Context $context, ?string $connectionName = null)
    {
        parent::__construct($context, $connectionName);
    }

    protected function _construct()
    {
        $this->_init('catalog_product_index_price_history', 'row_id');
    }
}