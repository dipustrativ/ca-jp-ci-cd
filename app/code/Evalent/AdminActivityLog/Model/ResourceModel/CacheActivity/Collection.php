<?php


namespace Evalent\AdminActivityLog\Model\ResourceModel\CacheActivity;

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
            'Evalent\AdminActivityLog\Model\CacheActivity',
            'Evalent\AdminActivityLog\Model\ResourceModel\CacheActivity'
        );
    }
}
