<?php

namespace IXPF\Syncer\Model\Config\Source;

use Magento\Catalog\Model\Product;
use Magento\Framework\Option\ArrayInterface;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Eav\Model\Entity\TypeFactory;

class AllowAttributes implements ArrayInterface
{

    public function __construct(
        protected AttributeCollectionFactory $attributeCollectionFactory,
        protected TypeFactory $entityTypeFactory
    )
    {
    }

    public function toOptionArray()
    {
        //-- get the product eav attribute type id
        $productEntityTypeId = $this->entityTypeFactory->create()->loadByCode(Product::ENTITY)->getId();

        $collection = $this->attributeCollectionFactory->create();
        $collection->setEntityTypeFilter($productEntityTypeId);
        $collection->setOrder('frontend_label', 'ASC');

        $attributes = [];
        foreach ($collection as $attribute) {
            $attributes[] = [
                'value' => $attribute->getAttributeCode(),
                'label' => $attribute->getDefaultFrontendLabel()
            ];
        }

       return $attributes;
    }
}