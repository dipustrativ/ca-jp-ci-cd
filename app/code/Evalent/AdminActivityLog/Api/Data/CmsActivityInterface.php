<?php

namespace Evalent\AdminActivityLog\Api\Data;

interface CmsActivityInterface
{

    const FULL_NAME = 'full_name';
    const STOREVIEW = 'storeview';
    const TITLE = 'title';
    const EDITED_ATTRIBUTES = 'edited_attributes';
    const OLD_CONTENT = 'old_content';
    const NEW_CONTENT = 'new_content';
    const USERNAME = 'username';
    const ACTION_TYPE = 'action_type';
    const CONTROLLER = 'controller';
    const CMSACTIVITY_ID = 'cmsactivity_id';
    const MODEL_TYPE = 'model_type';
    const DATETIME = 'datetime';


    /**
     * Get cmsactivity_id
     * @return string|null
     */
    public function getCmsactivityId();

    /**
     * Set cmsactivity_id
     *
     * @param $cmsactivityId
     *
     * @return \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface
     */
    public function setCmsactivityId($cmsactivityId);

    /**
     * Get username
     * @return string|null
     */
    public function getUsername();

    /**
     * Set username
     * @param string $username
     * @return \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface
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
     * @return \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface
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
     * @return \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface
     */
    public function setDatetime($datetime);

    /**
     * Get title
     * @return string|null
     */
    public function getTitle();

    /**
     * Set title
     *
     * @param string $title
     *
     * @return \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface
     */
    public function setTitle($title);

    /**
     * Get action_type
     * @return string|null
     */
    public function getActionType();

    /**
     * Set action_type
     * @param string $actionType
     * @return \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface
     */
    public function setActionType($actionType);

    /**
     * Get storeview
     * @return string|null
     */
    public function getStoreview();

    /**
     * Set storeview
     * @param string $storeview
     * @return \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface
     */
    public function setStoreview($storeview);

    /**
     * Get edited_attributes
     * @return string|null
     */
    public function getEditedAttributes();

    /**
     * Set edited_attributes
     *
     * @param string $editedAttributes
     *
     * @return \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface
     */
    public function setEditedAttributes($editedAttributes);

    /**
     * Get old_content
     * @return string|null
     */
    public function getOldContent();

    /**
     * Set old_content
     *
     * @param string $content
     *
     * @return \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface
     */
    public function setOldContent($content);

    /**
     * Get new_content
     * @return string|null
     */
    public function getNewContent();

    /**
     * Set new_content
     *
     * @param string $content
     *
     * @return \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface
     */
    public function setNewContent($content);

}
