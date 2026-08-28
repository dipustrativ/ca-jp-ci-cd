<?php


namespace Evalent\ProductCertificate\Model\Config\Source;


class ProductionType implements \Magento\Framework\Data\OptionSourceInterface
{
    /** @var \Magento\Catalog\Model\Product\Attribute\Repository */
    protected $attributeRepository;

    public function __construct(\Magento\Catalog\Model\Product\Attribute\Repository $attributeRepository){
        $this->attributeRepository = $attributeRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function toOptionArray()
    {
        /** @var \Magento\Eav\Model\Attribute $attribute */
        $attribute = $this->attributeRepository->get('production_type');
        $attribute->setStoreId(0);
        $options = $attribute->getOptions();

        return $options;
    }
}