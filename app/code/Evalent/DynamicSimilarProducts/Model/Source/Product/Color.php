<?php

declare(strict_types=1);

namespace Evalent\DynamicSimilarProducts\Model\Source\Product;

use Magento\Framework\Data\OptionSourceInterface;

class Color implements OptionSourceInterface
{
    private \Magento\Eav\Model\Config $eavConfig;

    public function __construct(
        \Magento\Eav\Model\Config $eavConfig
    ) {
        $this->eavConfig = $eavConfig;
    }

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' =>
     *               '<label>'), ...)
     */
    public function toOptionArray()
    {
        $attribute = $this->eavConfig->getAttribute('catalog_product', 'color');
        $options = $attribute->getSource()->getAllOptions();
        return $options;
    }
}
