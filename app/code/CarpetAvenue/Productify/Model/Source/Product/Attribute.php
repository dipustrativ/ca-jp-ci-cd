<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\Model\Source\Product;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Product attribute option source for the related carousel config.
 * Merged from Evalent_RelatedCarouselProducts (reference project).
 */
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
     * @return array
     */
    public function toOptionArray()
    {
        $productAttributes = $this->attributeFactory->getCollection()
            ->addFieldToFilter('entity_type_id', 4);
        $options = [];
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
