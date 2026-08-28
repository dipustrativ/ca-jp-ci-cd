<?php


namespace Evalent\AdminActivityLog\Model\ResourceModel;

class CacheActivity extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{

    /**
     * Define resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('evalent_adminactivitylog_cacheactivity', 'cacheactivity_id');
    }
}
