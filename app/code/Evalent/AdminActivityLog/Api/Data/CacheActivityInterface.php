<?php

namespace Evalent\AdminActivityLog\Api\Data;

interface CacheActivityInterface
{

    const FULL_NAME = 'full_name';
    const USERNAME = 'username';
    const ACTION_TYPE = 'action_type';
    const CACHE_TYPE = 'cache_type';
    const CACHEACTIVITY_ID = 'cacheactivity_id';
    const DATETIME = 'datetime';


    /**
     * Get modelactivity_id
     * @return string|null
     */
    public function getCacheactivityId();

    /**
     * Set cacheactivity_id
     * @param string $modelactivityId
     * @return \Evalent\AdminActivityLog\Api\Data\CacheActivityInterface
     */
    public function setCacheactivityId($modelactivityId);

    /**
     * Get username
     * @return string|null
     */
    public function getUsername();

    /**
     * Set username
     * @param string $username
     * @return \Evalent\AdminActivityLog\Api\Data\CacheActivityInterface
     */
    public function setUsername($username);

    /**
     * Get full_name
     * @return string|null
     */
    public function getFullName();

    /**
     * Set full_name
     * @param string $fullName
     * @return \Evalent\AdminActivityLog\Api\Data\CacheActivityInterface
     */
    public function setFullName($fullName);

    /**
     * Get datetime
     * @return string|null
     */
    public function getDatetime();

    /**
     * Set datetime
     * @param string $datetime
     * @return \Evalent\AdminActivityLog\Api\Data\CacheActivityInterface
     */
    public function setDatetime($datetime);
    /**
     * Get action_type
     * @return string|null
     */
    public function getActionType();

    /**
     * Set action_type
     * @param string $actionType
     * @return \Evalent\AdminActivityLog\Api\Data\CacheActivityInterface
     */
    public function setActionType($actionType);


    /**
     * Get cache_type
     * @return string|null
     */
    public function getCacheType();

    /**
     * Set cache_type
     * @param string $cacheType
     * @return \Evalent\AdminActivityLog\Api\Data\CacheActivityInterface
     */
    public function setCacheType($cacheType);
}
