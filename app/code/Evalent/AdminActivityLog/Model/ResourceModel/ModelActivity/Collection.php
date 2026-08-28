<?php


namespace Evalent\AdminActivityLog\Model\ResourceModel\ModelActivity;

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
            'Evalent\AdminActivityLog\Model\ModelActivity',
            'Evalent\AdminActivityLog\Model\ResourceModel\ModelActivity'
        );
    }
}
