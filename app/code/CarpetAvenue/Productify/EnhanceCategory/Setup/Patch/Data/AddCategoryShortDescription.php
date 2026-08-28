<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\EnhanceCategory\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Adds the "short_description" category attribute (textarea), shown in the
 * admin category form directly after the description field.
 */
class AddCategoryShortDescription implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $eavSetup->addAttribute(
            Category::ENTITY,
            'short_description',
            [
                'type' => 'text',
                'label' => 'Short Description',
                'input' => 'textarea',
                'sort_order' => 55,
                'source' => '',
                'global' => 0,
                'visible' => true,
                'required' => false,
                'user_defined' => false,
                'default' => null,
                'group' => 'General Information',
                'backend' => '',
            ]
        );

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * Previous FQCN, from before CarpetAvenue_EnhanceCategory was merged into
     * CarpetAvenue_Productify. See AddCategoryImageAttributes::getAliases().
     */
    public function getAliases(): array
    {
        // String literal, not ::class — the old class no longer exists.
        return [
            'CarpetAvenue\EnhanceCategory\Setup\Patch\Data\AddCategoryShortDescription',
        ];
    }
}
