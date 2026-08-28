<?php

namespace IXPF\Syncer\Services\Purchase;

use Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Eav\Model\Config;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Api\Data\AttributeOptionInterfaceFactory;
use Magento\Eav\Api\Data\AttributeOptionLabelInterfaceFactory;
use Magento\Eav\Api\AttributeOptionManagementInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Eav\Setup\EavSetupFactory;

class ProductAttribute
{
    protected array $cacheAttributes = [];
    protected array $cacheAttributesOptionValues = [];

    public function __construct(
        protected AttributeCollectionFactory $attributeCollectionFactory,
        protected EavSetupFactory            $eavSetupFactory,
        protected StoreManagerInterface      $storeManager
    )
    {
    }


    /**
     * @param int $entityTypeId
     * @return array
     */
    public function getAttributeList(int $entityTypeId = 4): array
    {
        $collection = $this->attributeCollectionFactory->create();
        $collection->setEntityTypeFilter($entityTypeId);

        $attributes = [];
        foreach ($collection as $attribute) {
            $attributes[trim($attribute->getAttributeCode())] = $attribute;
        }
        $this->cacheAttributes = $attributes;
        return $attributes;
    }

    /**
     * @param string $attributeCode
     * @return null|string
     */
    public function getAttributeInputType(string $attributeCode): ?string
    {
        if (isset($this->cacheAttributes[trim($attributeCode)])) {
            return $this->cacheAttributes[trim($attributeCode)]->getData('frontend_input');
        }

        return null;
    }

    /**
     * @param string $attributeCode
     * @return mixed|null
     */
    public function getAttribute(string $attributeCode): mixed
    {
        if (isset($this->cacheAttributes[trim($attributeCode)])) {
            return $this->cacheAttributes[trim($attributeCode)];
        }

        return null;
    }

    /**
     * @param string $attributeCode
     * @param string $label
     * @return mixed|string|void
     */
    public function getAttributeOptionValueFromLabel(string $attributeCode, string $label)
    {
        $originalLabel = trim($label);
        $label = trim($label);
        $attributeCode = trim($attributeCode);

        if (!isset($this->cacheAttributesOptionValues[$attributeCode])) {
            $options = $this->getAttributeOptionListWithLabelIdPair($attributeCode);
        } else {
            $options = $this->cacheAttributesOptionValues[$attributeCode];
        }

        if (isset($options[strtolower($label)])) {
            return $options[strtolower($label)];
        }

        $options = $this->createOptionsValue($attributeCode, $originalLabel);
        return ($options && isset($options[strtolower($label)])) ? $options[strtolower($label)] : null;
    }

    /**
     * @param string $attributeCode
     * @param string $label
     * @return array|null
     */
    public function createOptionsValue(string $attributeCode, string $label): ?array
    {
        try {
            $eavSetup = $this->eavSetupFactory->create();
            $option = [
                'attribute_id' => $eavSetup->getAttributeId('catalog_product', $attributeCode),
                'values' => [0 => $label],
            ];
            $eavSetup->addAttributeOption($option);

            return $this->getAttributeOptionListWithLabelIdPair($attributeCode);
        } catch (\Exception $exception) {
            return null;
        }
    }

    /**
     * @param string $attributeCode
     * @return array
     */
    public function getAttributeOptionListWithLabelIdPair(string $attributeCode): array
    {
        $attribute = $this->getAttribute(trim($attributeCode));
        $options = $attribute->setStoreId(0)->getSource()->getAllOptions(false);
        $attributeOptionValues = [];
        foreach ($options as $option) {
            if (!empty($option['value']) && !empty($option['label'])) {
                $attributeOptionValues[strtolower(trim($option['label']))] = $option['value'];
            }
        }
        $this->cacheAttributesOptionValues[trim($attributeCode)] = $attributeOptionValues;
        return $attributeOptionValues;
    }

    /**
     * @param string $attributeCode
     * @param mixed $value
     * @return mixed
     */
    public function getAttributeValue(string $attributeCode, mixed $value): mixed
    {
        $inputType = $this->getAttributeInputType($attributeCode);
        switch ($inputType) {
            case 'select':
                return $this->getAttributeOptionValueFromLabel($attributeCode, $value);
            case 'multiselect':
                //-- Normalize input into array
                if (is_array($value)) {
                    $values = $value;
                } elseif (is_string($value)) {
                    $values = explode(',', $value);
                } else {
                    return [];
                }

                $result = [];
                foreach ($values as $_value) {
                    $label = trim($_value);
                    if ($label === '') {
                        continue;
                    }

                    $optId = $this->getAttributeOptionValueFromLabel($attributeCode, $label);
                    if ($optId !== null && $optId !== '') {
                        $result[] = $optId;
                    }
                }
                return array_values(array_unique($result));
            case 'date':
            case 'boolean':
            case 'text':
            case 'textarea':
            case 'hidden':
            case 'price':
            default:
                return $value;

        }
    }
}