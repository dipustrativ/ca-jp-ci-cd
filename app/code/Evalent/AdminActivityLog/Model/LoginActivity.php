<?php


namespace Evalent\AdminActivityLog\Model;

use Evalent\AdminActivityLog\Api\Data\LoginActivityInterface;

class LoginActivity extends \Magento\Framework\Model\AbstractModel implements LoginActivityInterface
{

    const FAILED_LOGIN = 0;
    const SUCCESSFUL_LOGIN = 1;
    const LOGOUT = 2;

    protected $_eventPrefix = 'evalent_adminactivitylog_loginactivity';

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('Evalent\AdminActivityLog\Model\ResourceModel\LoginActivity');
    }

    /**
     * Get loginactivity_id
     * @return string
     */
    public function getLoginactivityId()
    {
        return $this->getData(self::LOGINACTIVITY_ID);
    }

    /**
     * Set loginactivity_id
     * @param string $loginactivityId
     * @return \Evalent\AdminActivityLog\Api\Data\LoginActivityInterface
     */
    public function setLoginactivityId($loginactivityId)
    {
        return $this->setData(self::LOGINACTIVITY_ID, $loginactivityId);
    }

    /**
     * Get username
     * @return string
     */
    public function getUsername()
    {
        return $this->getData(self::USERNAME);
    }

    /**
     * Set username
     * @param string $username
     * @return \Evalent\AdminActivityLog\Api\Data\LoginActivityInterface
     */
    public function setUsername($username)
    {
        return $this->setData(self::USERNAME, $username);
    }

    /**
     * Get ip_address
     * @return string
     */
    public function getIpAddress()
    {
        return $this->getData(self::IP_ADDRESS);
    }

    /**
     * Set ip_address
     * @param string $ipAddress
     * @return \Evalent\AdminActivityLog\Api\Data\LoginActivityInterface
     */
    public function setIpAddress($ipAddress)
    {
        return $this->setData(self::IP_ADDRESS, $ipAddress);
    }

    /**
     * Get status
     * @return string
     */
    public function getStatus()
    {
        return $this->getData(self::STATUS);
    }

    /**
     * Set status
     * @param string $status
     * @return \Evalent\AdminActivityLog\Api\Data\LoginActivityInterface
     */
    public function setStatus($status)
    {
        return $this->setData(self::STATUS, $status);
    }

    public function afterSave()
    {
        return null;
    }

    /**
     * Get time of the event
     * @return string|null
     */
    public function getEventTime()
    {
        return $this->getData(self::EVENT_TIME);
    }
}
