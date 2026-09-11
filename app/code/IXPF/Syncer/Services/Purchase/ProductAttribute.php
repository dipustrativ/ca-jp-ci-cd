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
use Magento\Eav\Model\Entity\Attribute\Source\Table as TableSource;

class ProductAttribute
{
    protected array $cacheAttributes = [];
    protected array $cacheAttributesOptionValues = [];

    /**
     * Valid option ids per attribute code (id => id)
     *
     * @var array<string, array>
     */
    protected array $cacheAttributesOptionIds = [];

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

        //-- payload may send the raw option id instead of the label (e.g. tax_class_id = "6")
        $optionId = $this->matchOptionId($attributeCode, $originalLabel);
        if ($optionId !== null) {
            return $optionId;
        }

        //-- attributes backed by a custom source model (tax_class_id, status, ...) do not
        //-- store their options in eav_attribute_option — never create options for them
        if (!$this->canCreateOption($attributeCode)) {
            return null;
        }

        $options = $this->createOptionsValue($attributeCode, $originalLabel);
        return ($options && isset($options[strtolower($label)])) ? $options[strtolower($label)] : null;
    }

    /**
     * Return the value as-is when it already is a valid option id of the attribute.
     *
     * @param string $attributeCode
     * @param string $value
     * @return string|null
     */
    public function matchOptionId(string $attributeCode, string $value): ?string
    {
        $attributeCode = trim($attributeCode);

        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        if (!isset($this->cacheAttributesOptionIds[$attributeCode])) {
            $this->getAttributeOptionListWithLabelIdPair($attributeCode);
        }

        $optionIds = $this->cacheAttributesOptionIds[$attributeCode] ?? [];
        $key = (string)(int)$value;

        return isset($optionIds[$key]) ? $key : null;
    }

    /**
     * Only plain option-table attributes may get new options created on the fly.
     *
     * @param string $attributeCode
     * @return bool
     */
    public function canCreateOption(string $attributeCode): bool
    {
        $attribute = $this->getAttribute(trim($attributeCode));
        if (!$attribute) {
            return false;
        }

        $sourceModel = trim((string)$attribute->getData('source_model'));

        return $sourceModel === ''
            || ltrim($sourceModel, '\\') === TableSource::class;
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
        $attributeOptionIds = [];
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
