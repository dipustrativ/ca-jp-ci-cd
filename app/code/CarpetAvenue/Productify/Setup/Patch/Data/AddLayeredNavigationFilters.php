<?php

namespace CarpetAvenue\Productify\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Eav\Setup\EavSetup;
use Magento\Catalog\Model\Product;

/**
 * Flag additional attributes as filterable so they appear in the storefront
 * layered-navigation filter bar (Productify uses Magento's
 * FilterableAttributeList, which reads is_filterable).
 *
 * Colour, Production Type and Price are already filterable; this adds Pattern,
 * Design and Shape. ("Material" has no attribute in this store.)
 *
 * NOTE: a catalog fulltext reindex is required after this patch for the new
 * filters to populate option counts.
 */
class AddLayeredNavigationFilters implements DataPatchInterface
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
     * Attribute codes to make filterable in layered navigation.
     *
     * @var string[]
     */
    private const ATTRIBUTE_CODES = [
        'product_pattern', // Pattern
        'design',          // Design
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
            if (!$eavSetup->getAttributeId(Product::ENTITY, $attributeCode)) {
                continue;
            }

            // 1 = "Filterable (with results)" in layered navigation.
            $eavSetup->updateAttribute(Product::ENTITY, $attributeCode, 'is_filterable', 1);
            $eavSetup->updateAttribute(Product::ENTITY, $attributeCode, 'is_filterable_in_search', 1);
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
