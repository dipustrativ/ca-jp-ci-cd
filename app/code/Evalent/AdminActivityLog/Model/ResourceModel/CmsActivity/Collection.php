<?php


namespace Evalent\AdminActivityLog\Model\ResourceModel\CmsActivity;

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
            'Evalent\AdminActivityLog\Model\CmsActivity',
            'Evalent\AdminActivityLog\Model\ResourceModel\CmsActivity'
        );
    }
}
