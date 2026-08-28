<?php

declare(strict_types=1);

namespace Evalent\DynamicSimilarProducts\Model\Source\Product;

use Magento\Framework\Data\OptionSourceInterface;

class AttributeSortby implements OptionSourceInterface
{

    /**
     * Catalog config
     *
     * @var \Magento\Catalog\Model\Config
     */
    protected $_catalogConfig;

    public function __construct(
        \Magento\Catalog\Model\Config $catalogConfig
    ) {
        $this->_catalogConfig = $catalogConfig;
    }

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' =>
     *               '<label>'), ...)
     */
    public function toOptionArray()
    {
        $productAttributes = $this->_catalogConfig->getAttributesUsedForSortBy();
        $options = [];
        /** @var \Magento\Eav\Model\Attribute $attribute */
        foreach ($productAttributes as $attribute) {

            $options[] = [
                'value' => $attribute['attribute_code'],
                'label' => __($attribute['frontend_label'])
            ];

        }
        return $options;
    }
}
