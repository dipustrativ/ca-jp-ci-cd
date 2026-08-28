<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\ThemeContent\Helper;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;

/**
 * Theme helper. Migrated from the (base-project) Evalent_CarpetTheme module so
 * the rug theme templates no longer depend on a module that is absent here.
 */
class ThemeHelper extends AbstractHelper
{
    /**
     * Configurable "size" attribute shared across rug product templates.
     */
    private const SIZE_ATTRIBUTE_CODE = 'configurable_size';

    /**
     * @var EavConfig
     */
    private $eavConfig;

    /**
     * @param Context $context
     * @param EavConfig $eavConfig
     */
    public function __construct(
        Context $context,
        EavConfig $eavConfig
    ) {
        parent::__construct($context);
        $this->eavConfig = $eavConfig;
    }

    /**
     * Attribute id of the configurable size attribute.
     *
     * @return int|null
     */
    public function getSizeAttributeId(): ?int
    {
        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, self::SIZE_ATTRIBUTE_CODE);
        $attributeId = $attribute ? $attribute->getAttributeId() : null;

        return $attributeId ? (int) $attributeId : null;
    }
}
