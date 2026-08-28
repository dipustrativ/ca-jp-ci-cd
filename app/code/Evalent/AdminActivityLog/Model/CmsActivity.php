<?php


namespace Evalent\AdminActivityLog\Model;

use Evalent\AdminActivityLog\Api\Data\CmsActivityInterface;

class CmsActivity extends \Magento\Framework\Model\AbstractModel implements CmsActivityInterface
{

    protected $_eventPrefix = 'evalent_adminactivitylog_cmsactivity';

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('Evalent\AdminActivityLog\Model\ResourceModel\CmsActivity');
    }

    /**
     * Get cmsactivity_id
     * @return string
     */
    public function getCmsactivityId()
    {
        return $this->getData(self::CMSACTIVITY_ID);
    }

    /**
     * Set cmsactivity_id
     *
     * @param string $cmsactivityId
     *
     * @return \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface
     */
    public function setCmsactivityId($cmsactivityId)
    {
        return $this->setData(self::CMSACTIVITY_ID, $cmsactivityId);
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
     * @return \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface
     */
    public function setUsername($username)
    {
        return $this->setData(self::USERNAME, $username);
    }

    /**
     * Get full_name
     * @return string
     */
    public function getFullName()
    {
        return $this->getData(self::FULL_NAME);
    }

    /**
     * Set full_name
     * @param string $fullName
     * @return \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface
     */
    public function setFullName($fullName)
    {
        return $this->setData(self::FULL_NAME, $fullName);
    }

    /**
     * Get datetime
     * @return string
     */
    public function getDatetime()
    {
        return $this->getData(self::DATETIME);
    }

    /**
     * Set datetime
     * @param string $datetime
     * @return \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface
     */
    public function setDatetime($datetime)
    {
        return $this->setData(self::DATETIME, $datetime);
    }

    /**
     * Get controller
     * @return string
     */
    public function getController()
    {
        return $this->getData(self::CONTROLLER);
    }

    /**
     * Set controller
     * @param string $controller
     * @return \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface
     */
    public function setController($controller)
    {
        return $this->setData(self::CONTROLLER, $controller);
    }

    /**
     * Get action_type
     * @return string
     */
    public function getActionType()
    {
        return $this->getData(self::ACTION_TYPE);
    }

    /**
     * Set action_type
     * @param string $actionType
     * @return \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface
     */
    public function setActionType($actionType)
    {
        return $this->setData(self::ACTION_TYPE, $actionType);
    }

    /**
     * Get storeview
     * @return string
     */
    public function getStoreview()
    {
        return $this->getData(self::STOREVIEW);
    }

    /**
     * Set storeview
     * @param string $storeview
     * @return \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface
     */
    public function setStoreview($storeview)
    {
        return $this->setData(self::STOREVIEW, $storeview);
    }

    /**
     * Get edited_attributes
     * @return string
     */
    public function getEditedAttributes()
    {
        return $this->getData(self::EDITED_ATTRIBUTES);
    }

    /**
     * Set edited_attributes
     *
     * @param string $editedAttributes
     *
     * @return \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface
     */
    public function setEditedAttributes($editedAttributes)
    {
        return $this->setData(self::EDITED_ATTRIBUTES, $editedAttributes);
    }

    /**
     * Get title
     *
     * @return string|null
     */
    public function getTitle()
    {
        return $this->getData(self::TITLE);
    }

    /**
     * Set title
     *
     * @param string $title
     *
     * @return \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface
     */
    public function setTitle($title)
    {
        return $this->setData(self::TITLE, $title);
    }

    /**
     * Get old_content
     *
     * @return string|null
     */
    public function getOldContent()
    {
        return $this->getData(self::OLD_CONTENT);
    }

    /**
     * Set old_content
     *
     * @param string $content
     *
     * @return \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface
     */
    public function setOldContent($content)
    {
        return $this->setData(self::OLD_CONTENT, $content);
    }

    /**
     * Get new_content
     *
     * @return string|null
     */
    public function getNewContent()
    {
        return $this->getData(self::NEW_CONTENT);
    }

    /**
     * Set new_content
     *
     * @param string $content
     *
     * @return \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface
     */
    public function setNewContent($content)
    {
        return $this->setData(self::NEW_CONTENT, $content);
    }

    public function afterSave()
    {
    }
}
