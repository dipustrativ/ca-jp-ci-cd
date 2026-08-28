<?php

declare(strict_types=1);

namespace Evalent\DynamicSimilarProducts\Model\Source\Product;

use Magento\Framework\Data\OptionSourceInterface;

class Attribute implements OptionSourceInterface
{
    /**
     * @var \Magento\Catalog\Model\ResourceModel\Eav\Attribute
     */
    private $attributeFactory;

    public function __construct(
        \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attributeFactory
    ) {
        $this->attributeFactory = $attributeFactory;
    }

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' =>
     *               '<label>'), ...)
     */
    public function toOptionArray()
    {
        $productAttributes = $this->attributeFactory->getCollection()
            ->addFieldToFilter('entity_type_id', 4);
        $options = [
            'value' => '',
            'label' => ' -- Select --'
        ];
        /** @var \Magento\Eav\Model\Attribute $attribute */
        foreach ($productAttributes as $attribute) {
            $options[] = [
                'value' => $attribute->getAttributeCode(),
                'label' => $attribute->getDefaultFrontendLabel()
            ];
        }
        return $options;
    }
}
