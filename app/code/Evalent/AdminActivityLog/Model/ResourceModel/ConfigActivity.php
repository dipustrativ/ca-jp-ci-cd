<?php


namespace Evalent\AdminActivityLog\Model\ResourceModel;

class ConfigActivity extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{

    /**
     * Define resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('evalent_adminactivitylog_configactivity', 'configactivity_id');
    }
}
