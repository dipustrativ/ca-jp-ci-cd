<?php

namespace CarpetAvenue\Proforma\Model\ResourceModel;

class Proforma extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb{
    protected function _construct()
    {
        $this->_init('sales_proforma', 'entity_id');
    }
    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context,
        protected \CarpetAvenue\Proforma\Model\ResourceModel\ProformaItem $proformaItemResource,
        protected \CarpetAvenue\Proforma\Model\ResourceModel\ProformaItem\CollectionFactory $proformaItemCollectionFactory,
        $connectionName = null
    ){
        parent::__construct($context, $connectionName);
    }
    public function save(\Magento\Framework\Model\AbstractModel $object): static{
        parent::save($object);
        foreach($object->getItems() as $item){
            $item->setProformaId($object->getEntityId());
            $this->proformaItemResource->save($item);
        }
        //save items
        return $this;
    }
    public function load(\Magento\Framework\Model\AbstractModel $object, $value, $field = null): static{
        parent::load($object,$value, $field);
        // @var \CarpetAvenue\Proforma\Model\ResourceModel\ProformaItem\Collection $collection
        $collection = $this->proformaItemCollectionFactory->create();
        $collection->addFieldToFilter('proforma_id', ['eq' => $object->getEntityId()]);
        foreach ($collection->getItems() as $item){
            $object->addItem($item);
        }
        return $this;
    }
}