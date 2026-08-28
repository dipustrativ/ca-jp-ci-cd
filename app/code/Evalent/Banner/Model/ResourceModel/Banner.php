<?php

declare(strict_types=1);

namespace Evalent\Banner\Model\ResourceModel;

use Magento\Backend\Model\Auth\Session;
use Magento\Catalog\Model\ResourceModel\AbstractResource;
use Magento\Catalog\Model\ResourceModel\Category;
use Magento\Catalog\Model\ResourceModel\Product;
use Magento\Eav\Model\Entity;
use Magento\Eav\Model\Entity\AbstractEntity;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\UniqueValidationInterface;
use Magento\Eav\Model\Entity\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Authorization\PolicyInterface;
use Magento\Framework\DataObject;
use Magento\Framework\EntityManager\EntityManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

class Banner extends AbstractEntity
{


    /**
     * @var EntityManager
     */
    protected $entityManager;

    /**
     * Store id
     *
     * @var int
     */
    protected $_storeId = null;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    public function __construct(
        StoreManagerInterface $storeManager,
        Context $context,
        $data = [],
        ?UniqueValidationInterface $uniqueValidator = null
    ) {
        parent::__construct($context, $data, $uniqueValidator);
        $this->storeManager = $storeManager;
    }

    /**
     * Save entity's attributes into the object's resource
     *
     * @param \Magento\Framework\Model\AbstractModel $object
     *
     * @return AbstractModel
     * @throws \Exception
     */
    public function save(AbstractModel $object)
    {
        $dataModel = $object->getDataModel();
        $dataModel->setData($object->getData());
        $this->getEntityManager()->save($dataModel);
        return $this;
    }

    /**
     * Retrieve select object for loading entity attributes values
     *
     * Join attribute store value
     *
     * @param \Magento\Framework\DataObject $object
     * @param string                        $table
     *
     * @return \Magento\Framework\DB\Select
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function _getLoadAttributesSelect($object, $table)
    {
        /**
         * This condition is applicable for all cases when we was work in not single
         * store mode, customize some value per specific store view and than back
         * to single store mode. We should load correct values
         */
        if ($this->storeManager->hasSingleStore()) {
            $storeId = (int)$this->storeManager->getStore(true)->getId();
        } else {
            $storeId = (int)$object->getStoreId();
        }

        $storeIds = [$this->getDefaultStoreId()];
        if ($storeId != $this->getDefaultStoreId()) {
            $storeIds[] = $storeId;
        }

        $select = $this->getConnection()
            ->select()
            ->from(['attr_table' => $table], [])
            ->where("attr_table.{$this->getLinkField()} = ?",
                $object->getData($this->getLinkField()))
            ->where('attr_table.store_id IN (?)', $storeIds);

        return $select;
    }

    /**
     * Returns default Store ID
     *
     * @return int
     */
    public function getDefaultStoreId()
    {
        return Store::DEFAULT_STORE_ID;
    }

    /**
     * Returns EntityManager object
     *
     * @return EntityManager
     */
    private function getEntityManager()
    {
        if (null === $this->entityManager) {
            $this->entityManager
                = ObjectManager::getInstance()
                ->get(EntityManager::class);
        }
        return $this->entityManager;
    }

    /**
     * @inheritdoc
     */
    public function delete($object)
    {
        $this->getEntityManager()->delete($object->getDataModel());
        return $this;
    }


    /**
     * Entity type getter and lazy loader
     *
     * @return \Magento\Eav\Model\Entity\Type
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getEntityType()
    {
        if (empty($this->_type)) {
            $this->setType(\Evalent\Banner\Model\Banner::ENTITY);
        }
        return parent::getEntityType();
    }

    protected function _getDefaultAttributes()
    {
        return [
            'name',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * Set store Id
     *
     * @param integer $storeId
     *
     * @return $this
     */
    public function setStoreId($storeId)
    {
        $this->_storeId = $storeId;
        return $this;
    }

    /**
     * Return store id
     *
     * @return integer
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getStoreId()
    {
        if ($this->_storeId === null) {
            return $this->storeManager->getStore()->getId();
        }
        return $this->_storeId;
    }


    /**
     * Set Attribute values to be saved
     *
     * @param \Magento\Framework\Model\AbstractModel                $object
     * @param \Magento\Eav\Model\Entity\Attribute\AbstractAttribute $attribute
     * @param mixed                                                 $value
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _saveAttribute($object, $attribute, $value)
    {
        $table = $attribute->getBackend()->getTable();
        if (!isset($this->_attributeValuesToSave[$table])) {
            $this->_attributeValuesToSave[$table] = [];
        }

        $entityIdField = $attribute->getBackend()->getEntityIdField();

        $data = [
            $entityIdField => $object->getId(),
            'attribute_id' => $attribute->getId(),
            'value'        => $this->_prepareValueForSave($value, $attribute),
            'store_id'     => $object->getStoreId(),
        ];

        if (!$this->getEntityTable()
            || $this->getEntityTable()
            == Entity::DEFAULT_ENTITY_TABLE
        ) {
            $data['entity_type_id'] = $object->getEntityTypeId();
        }

        $this->_attributeValuesToSave[$table][] = $data;

        return $this;
    }


}
