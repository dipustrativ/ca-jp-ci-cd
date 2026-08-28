<?php


namespace Evalent\AdminActivityLog\Model\ResourceModel\LoginActivity;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{

    /**
     * Define resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(
            'Evalent\AdminActivityLog\Model\LoginActivity',
            'Evalent\AdminActivityLog\Model\ResourceModel\LoginActivity'
        );
    }
}
