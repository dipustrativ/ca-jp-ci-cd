<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\Model\Config\Source;

use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class ProductionType implements \Magento\Framework\Data\OptionSourceInterface
{

    /**
     * @var \Magento\Eav\Api\AttributeRepositoryInterface
     */
    private $attributeRepository;

    /**
     * ProductionType constructor.
     *
     * @param \Magento\Eav\Api\AttributeRepositoryInterface $attributeRepository
     */
    public function __construct(
        AttributeRepositoryInterface $attributeRepository
    ) {
        $this->attributeRepository = $attributeRepository;
    }

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' => '<label>'), ...)
     */
    public function toOptionArray(): array
    {
        try {
            $productTypeAttribute = $this->attributeRepository->get('catalog_product', 'production_type');
        } catch (NoSuchEntityException $e) {
            return [];
        }
        $attributeOptions = $productTypeAttribute->getOptions();
        $return = [];
        foreach ($attributeOptions as $attributeOption) {
            $return[] = [
                'value' => $attributeOption->getValue(),
                'label' => $attributeOption->getLabel()
            ];
        }
        return $return;
    }
}
