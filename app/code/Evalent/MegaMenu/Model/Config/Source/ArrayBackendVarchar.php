<?php

declare(strict_types=1);

namespace Evalent\MegaMenu\Model\Config\Source;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\Backend\AbstractBackend;
use Magento\Framework\Exception\LocalizedException;

class ArrayBackendVarchar extends AbstractBackend
{
    /**
     * Implode data for validation
     *
     * @param Product $object
     * @return bool
     * @throws LocalizedException
     */
    public function validate($object): bool
    {
        $attributeCode = $this->getAttribute()->getAttributeCode();
        if ($object->hasData($attributeCode)) {
            $data = $object->getData($attributeCode);
            if (is_array($data)) {
                $object->setData($attributeCode, $this->prepareVarchar($data));
            } elseif (is_string($data)) {
                $object->setData($attributeCode, $this->prepareVarchar(explode(',', $data)));
            } elseif (empty($data)) {
                $object->setData($attributeCode, null);
            }
        }

        return parent::validate($object);
    }

    /**
     * Prepare attribute values
     *
     * @param array $data
     * @return string
     */
    private function prepareVarchar(array $data): string
    {
        return implode(',', array_filter(array_unique($data), 'is_string'));
    }

}
