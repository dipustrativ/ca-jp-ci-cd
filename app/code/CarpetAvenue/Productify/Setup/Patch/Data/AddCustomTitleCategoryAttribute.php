<?php

namespace CarpetAvenue\Productify\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddCustomTitleCategoryAttribute implements DataPatchInterface
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
            'custom_title',
            [
                'type' => 'varchar',
                'label' => 'Custom Title',
                'input' => 'text',
                'required' => false,
                'sort_order' => 1,
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'group' => 'Display Settings',
            ]
        );
        $this->moduleDataSetup->getConnection()->endSetup();
    }
}