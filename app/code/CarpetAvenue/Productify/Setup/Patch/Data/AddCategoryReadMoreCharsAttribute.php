<?php

namespace CarpetAvenue\Productify\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddCategoryReadMoreCharsAttribute implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public static function getDependencies(): array
    {
        return [AddCategoryDisplayAttributes::class];
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
            'hide_bottom_block_link',
            [
                'type'       => 'int',
                'label'      => 'Hide Links in Bottom Block',
                'input'      => 'boolean',
                'required'   => false,
                'sort_order' => 7,
                'global'     => ScopedAttributeInterface::SCOPE_STORE,
                'group'      => 'Display Settings',
            ]
        );

        $eavSetup->addAttribute(
            Category::ENTITY,
            'description_read_more_chars',
            [
                'type'       => 'int',
                'label'      => 'Read More Character Limit',
                'input'      => 'text',
                'required'   => false,
                'sort_order' => 8,
                'global'     => ScopedAttributeInterface::SCOPE_STORE,
                'group'      => 'Display Settings',
            ]
        );

        $this->moduleDataSetup->getConnection()->endSetup();
    }
}
