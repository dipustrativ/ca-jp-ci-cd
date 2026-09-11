<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean as BooleanSource;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * "On sale" flag behind the カーペット販売中 filter in the price panel.
 *
 * A product is on sale when its final price is below its regular price, which
 * covers special prices, catalog rules and tier prices alike. The value is not
 * entered by hand — CarpetAvenue\Productify\Model\OnSale\FlagUpdater recomputes
 * it from the price index on a cron, so the attribute is hidden from the product
 * form.
 *
 * NAME: "am_" is Amasty's vendor prefix and we do not run Amasty — the code is
 * deliberately identical to carpetavenue.jp's so the filter URL matches live
 * byte for byte (?am_on_sale=1). Nothing here depends on Amasty.
 *
 * A catalog fulltext reindex is required after this patch before the filter
 * shows option counts.
 */
class AddOnSaleAttribute implements DataPatchInterface
{
    public const ATTRIBUTE_CODE = 'am_on_sale';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        if (!$eavSetup->getAttributeId(Product::ENTITY, self::ATTRIBUTE_CODE)) {
            $eavSetup->addAttribute(
                Product::ENTITY,
                self::ATTRIBUTE_CODE,
                [
                    'type'                    => 'int',
                    'label'                   => 'On Sale',
                    'input'                   => 'boolean',
                    'source'                  => BooleanSource::class,
                    'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                    //  Maintained by cron, so it has no place on the product form.
                    'visible'                 => false,
                    'required'                => false,
                    'user_defined'            => true,
                    'default'                 => 0,
                    'searchable'              => false,
                    'comparable'              => false,
                    'visible_on_front'        => false,
                    'used_in_product_listing' => true,
                    'unique'                  => false,
                    'apply_to'                => '',
                ]
            );
        }

        // 1 = "Filterable (with results)". This is what puts it in front of the
        // layered navigation, which reads is_filterable via FilterableAttributeList.
        $eavSetup->updateAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, 'is_filterable', 1);
        $eavSetup->updateAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, 'is_filterable_in_search', 1);

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * {@inheritdoc}
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public static function getDependencies(): array
    {
        return [];
    }
}
