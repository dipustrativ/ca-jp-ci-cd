<?php

namespace Evalent\AdminActivityLog\Api\Data;

interface ConfigActivityInterface
{

    const FULL_NAME = 'full_name';
    const STOREVIEW = 'storeview';
    const SECTION = 'section';
    const WEBSITE = 'website';
    const EDITED_ATTRIBUTES = 'edited_attributes';
    const USERNAME = 'username';
    const CONFIGACTIVITY_ID = 'configactivity_id';
    const DATETIME = 'datetime';


    /**
     * Get configactivity_id
     * @return string|null
     */
    public function getConfigactivityId();

    /**
     * Set configactivity_id
     * @param string $configactivityId
     * @return \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterface
     */
    public function setConfigactivityId($configactivityId);

    /**
     * Get username
     * @return string|null
     */
    public function getUsername();

    /**
     * Set username
     * @param string $username
     * @return \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterface
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
     * @return \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterface
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
     * @return \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterface
     */
    public function setDatetime($datetime);

    /**
     * Get storeview
     * @return string|null
     */
    public function getStoreview();

    /**
     * Set storeview
     * @param string $storeview
     * @return \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterface
     */
    public function setStoreview($storeview);

    /**
     * Get website
     * @return string|null
     */
    public function getWebsite();

    /**
     * Set website
     *
     * @param string $website
     *
     * @return \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterface
     */
    public function setWebsite($website);

    /**
     * Get edited_attributes
     * @return string|null
     */
    public function getEditedAttributes();

    /**
     * Set edited_attributes
     *
     * @param string $EditedAttributes
     *
     * @return \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterface
     */
    public function setEditedAttributes($EditedAttributes);
    
    /**
     * Get section
     * @return string|null
     */
    public function getSection();

    /**
     * Set section
     *
     * @param string $section
     *
     * @return \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterface
     */
    public function setSection($section);

}
