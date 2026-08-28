<?php

namespace CarpetAvenue\Productify\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use CarpetAvenue\Productify\Model\Config\Source\BannerList;
use CarpetAvenue\Productify\Model\Config\Source\BlockList;

class AddBannerAttribute implements DataPatchInterface
{
    public function __construct(
        private ModuleDataSetupInterface $moduleDataSetup,
        private EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply()
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $eavSetup->addAttribute(
            Category::ENTITY,
            'top_banner',
            [
                'type' => 'int',
                'label' => 'Top Banner',
                'input' => 'select',
                'source' => BannerList::class,
                'required' => false,
                'sort_order' => 100,
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'group' => 'Display Settings',
                'is_used_in_grid' => false,
                'is_visible_in_grid' => false,
                'is_filterable_in_grid' => false,
            ]
        );

        $eavSetup->addAttribute(
            Category::ENTITY,
            'custom_bottom_block',
            [
                'type' => 'varchar',
                'label' => 'Page Bottom Block',
                'input' => 'select',
                'source' => BlockList::class,
                'required' => false,
                'sort_order' => 130,
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'group' => 'Display Settings',
                'is_used_in_grid' => false,
                'is_visible_in_grid' => false,
                'is_filterable_in_grid' => false,
            ]
        );

        return $this;
    }

    public static function getDependencies()
    {
        return [];
    }

    public function getAliases()
    {
        return [];
    }
}
