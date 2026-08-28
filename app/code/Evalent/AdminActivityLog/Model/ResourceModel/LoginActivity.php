<?php


namespace Evalent\AdminActivityLog\Model\ResourceModel;

class LoginActivity extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{

    /**
     * Define resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('evalent_adminactivitylog_loginactivity', 'loginactivity_id');
    }
}
