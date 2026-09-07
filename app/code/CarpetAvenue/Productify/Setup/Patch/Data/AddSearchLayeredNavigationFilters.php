<?php

namespace CarpetAvenue\Productify\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Give the search results page the same layered-navigation filters as a
 * category listing.
 *
 * Category and search use two different flags and two different filter lists:
 * Magento's `Magento\LayeredNavigation\Block\Navigation\Category` and
 * `...\Navigation\Search` are virtualTypes of the same block differing only in
 * their `filterList` argument — `categoryFilterList` reads `is_filterable`,
 * `searchFilterList` reads `is_filterable_in_search`. Only Design and Pattern
 * carried the search flag (AddLayeredNavigationFilters set it for those two and
 * for Shape, but Shape's has since been cleared), and neither yields options on
 * most queries. With no filter returning any item `Navigation::canShowBlock()`
 * is false, so the storefront rendered *no* filter bar at all on search —
 * no filter buttons, no slide-out modal, and therefore no floating filter pill.
 *
 * This mirrors the category flag onto the search flag for every attribute the
 * category bar already offers, so the two surfaces stay in step. Attributes
 * with no usable values simply return no items and stay hidden, exactly as they
 * already do on category pages.
 *
 * NOTE: a catalog_search fulltext reindex is required after this patch before
 * the new filters populate option counts.
 */
class AddSearchLayeredNavigationFilters implements DataPatchInterface
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
     * Attributes already filterable in category navigation that were never
     * flagged for search. Design and Pattern are omitted — they already carry
     * the flag from AddLayeredNavigationFilters.
     *
     * @var string[]
     */
    private const ATTRIBUTE_CODES = [
        'color',            // Colour
        'height2',          // Length  — half of the merged "Size" section
        'width2',           // Width   — half of the merged "Size" section
        'manufacturer',     // Manufacturer
        'price',            // Price
        'production_type',  // Production type
        'shape',            // Shape
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

            // 1 = "Filterable (with results)" — the same setting the category
            // bar uses, so an attribute with no matching values stays hidden.
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
        return [
            AddLayeredNavigationFilters::class,
        ];
    }
}
