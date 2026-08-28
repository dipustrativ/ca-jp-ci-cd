<?php

namespace Evalent\AdminActivityLog\Api\Data;

interface LoginActivityInterface
{

    const LOGINACTIVITY_ID = 'loginactivity_id';
    const USERNAME = 'username';
    const STATUS = 'status';
    const IP_ADDRESS = 'ip_address';
    const EVENT_TIME = 'event_time';


    /**
     * Get loginactivity_id
     * @return string|null
     */
    public function getLoginactivityId();

    /**
     * Set loginactivity_id
     * @param string $loginactivityId
     * @return \Evalent\AdminActivityLog\Api\Data\LoginActivityInterface
     */
    public function setLoginactivityId($loginactivityId);

    /**
     * Get username
     * @return string|null
     */
    public function getUsername();

    /**
     * Set username
     * @param string $username
     * @return \Evalent\AdminActivityLog\Api\Data\LoginActivityInterface
     */
    public function setUsername($username);

    /**
     * Get ip_address
     * @return string|null
     */
    public function getIpAddress();

    /**
     * Set ip_address
     * @param string $ipAddress
     * @return \Evalent\AdminActivityLog\Api\Data\LoginActivityInterface
     */
    public function setIpAddress($ipAddress);

    /**
     * Get status
     * @return string|null
     */
    public function getStatus();

    /**
     * Set status
     * @param string $status
     * @return \Evalent\AdminActivityLog\Api\Data\LoginActivityInterface
     */
    public function setStatus($status);

    /**
     * Get time of the event
     * @return string|null
     */
    public function getEventTime();

}
