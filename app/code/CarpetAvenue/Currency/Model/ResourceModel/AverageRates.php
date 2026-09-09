<?php

namespace CarpetAvenue\Currency\Model\ResourceModel;

class AverageRates extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb{
    protected function _construct()
    {
        $this->_init('carpet_currency_rates', 'id');
    }

    /**
     * @inheritDoc
     */
    protected function _getLoadSelect($field, $value, $object){
        $select = parent::_getLoadSelect($field, $value, $object);
        $select->columns([
            'year' => new \Zend_Db_Expr('year(carpet_currency_rates.rate_time)'),
            'month' => new \Zend_Db_Expr('month(carpet_currency_rates.rate_time)')
        ]);
        return $select;
    }
}