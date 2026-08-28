<?php

namespace CarpetAvenue\Productify\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddCategoryDisplayAttributes implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public static function getDependencies(): array
    {
        return [AddCategoryBadgeAttribute::class];
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
            'description_read_more',
            [
                'type' => 'int',
                'label' => 'Enable Read More for Description',
                'input' => 'boolean',
                'required' => false,
                'sort_order' => 5,
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'group' => 'Display Settings',
            ]
        );

        $eavSetup->addAttribute(
            Category::ENTITY,
            'category_custom_css',
            [
                'type' => 'text',
                'label' => 'Custom CSS',
                'input' => 'textarea',
                'required' => false,
                'sort_order' => 6,
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'group' => 'Display Settings',
            ]
        );

        $this->moduleDataSetup->getConnection()->endSetup();
    }
}
