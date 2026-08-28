<?php

namespace CarpetAvenue\Productify\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddCategoryBadgeAttribute implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    )
    {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $eavSetup->addAttribute(
            Category::ENTITY,
            'megamenu_badge',
            [
                'type' => 'varchar',
                'label' => 'Menu Badge',
                'input' => 'text',
                'required' => false,
                'sort_order' => 2,
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'group' => 'Display Settings',
            ]
        );
        $eavSetup->addAttribute(
            Category::ENTITY,
            'megamenu_badge_color',
            [
                'type' => 'varchar',
                'label' => 'Menu Badge Color',
                'input' => 'text',
                'required' => false,
                'sort_order' => 3,
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'group' => 'Display Settings',
            ]
        );
        $eavSetup->addAttribute(
            Category::ENTITY,
            'show_description_top',
            [
                'type' => 'int',
                'label' => 'Show Description on Top',
                'input' => 'boolean',
                'required' => false,
                'sort_order' => 4,
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'group' => 'Display Settings',
            ]
        );
        $this->moduleDataSetup->getConnection()->endSetup();
    }
}