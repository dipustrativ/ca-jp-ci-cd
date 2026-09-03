<?php

namespace CarpetAvenue\Productify\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Eav\Setup\EavSetup;
use Magento\Catalog\Model\Product;

/**
 * Flag the specification attributes as "Visible on Catalog Pages on Storefront"
 * (is_visible_on_front) so they render in the PDP Specifications table
 * (product/view/attributes.phtml, driven by the core Attributes block's
 * getAdditionalData()).
 *
 * "Size" is composed by the template from width + length, so both are flagged.
 * "Signed" maps to the `signature` attribute; "Product type" to `production_type`;
 * "Knots per m²" to `knot_density`; "Detail colors" to `detail_color`.
 */
class ShowSpecificationAttributesOnPdp implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var EavSetupFactory
     */
    private $eavSetupFactory;

    /**
     * Attribute codes to expose in the PDP specifications table.
     *
     * @var string[]
     */
    private const ATTRIBUTE_CODES = [
        'production_type', // Product type
        'signature',       // Signed
        'width',           // Size (width x length)
        'length',          // Size
        'thickness',       // Thickness
        'weight',          // Weight
        'origin',          // Origin
        'pile',            // Pile (pile/warp)
        'warp',            // Warp (pile/warp)
        'knot_density',    // Knots per m²
        'primary_color',   // Primary color
        'detail_color',    // Detail colors
        'field_color',     // Field color
        'border_color',    // Border color
        // Also expose the filter-style attributes on the PDP spec table.
        'color',           // Color
        'design',          // Design
        'product_pattern', // Pattern
        'shape',           // Shape
    ];

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param EavSetupFactory $eavSetupFactory
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        EavSetupFactory $eavSetupFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function apply(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        foreach (self::ATTRIBUTE_CODES as $attributeCode) {
            // Skip codes not present in this install rather than aborting the patch.
            if (!$eavSetup->getAttributeId(Product::ENTITY, $attributeCode)) {
                continue;
            }

            $eavSetup->updateAttribute(
                Product::ENTITY,
                $attributeCode,
                'is_visible_on_front',
                1
            );
        }

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * {@inheritdoc}
     */
    public function getAliases()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public static function getDependencies()
    {
        return [];
    }
}
