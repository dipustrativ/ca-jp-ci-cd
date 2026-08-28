<?php

namespace Evalent\AdminActivityLog\Api\Data;

interface ModelActivityInterface
{

    const FULL_NAME = 'full_name';
    const STOREVIEW = 'storeview';
    const EDITED_ATTRIBUTES = 'edited_attributes';
    const USERNAME = 'username';
    const ACTION_TYPE = 'action_type';
    const MODEL_ENTITY_ID = 'model_entity_id';
    const MODELACTIVITY_ID = 'modelactivity_id';
    const MODEL_TYPE = 'model_type';
    const DATETIME = 'datetime';


    /**
     * Get modelactivity_id
     * @return string|null
     */
    public function getModelactivityId();

    /**
     * Set modelactivity_id
     * @param string $modelactivityId
     * @return \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface
     */
    public function setModelactivityId($modelactivityId);

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
     * Get model_enity_id
     * @return string|null
     */
    public function getModelEntityId();

    /**
     * Set model_enity_id
     *
     * @param string $modelEntityId
     *
     * @return \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface
     */
    public function setModelEntityId($modelEntityId);

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
     * @param string $EditedAttributes
     *
     * @return \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface
     */
    public function setEditedAttributes($EditedAttributes);

    /**
     * Get model_type
     * @return string|null
     */
    public function getModelType();

    /**
     * Set model_type
     * @param string $modelType
     * @return \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface
     */
    public function setModelType($modelType);
}
