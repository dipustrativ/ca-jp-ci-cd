<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\EnhanceCategory\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Adds the "thumbnail" and "mobile_image" category attributes
 * (ported from Evalent_AdditionalCategoryImages InstallData).
 */
class AddCategoryImageAttributes implements DataPatchInterface
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

        foreach (['thumbnail' => 'Thumbnail', 'mobile_image' => 'Mobile Image'] as $code => $label) {
            $eavSetup->addAttribute(
                Category::ENTITY,
                $code,
                [
                    'type' => 'varchar',
                    'label' => $label,
                    'input' => 'image',
                    'sort_order' => 333,
                    'source' => '',
                    'global' => 0,
                    'visible' => true,
                    'required' => false,
                    'user_defined' => false,
                    'default' => null,
                    'group' => 'General Information',
                    'backend' => \Magento\Catalog\Model\Category\Attribute\Backend\Image::class,
                ]
            );
        }

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * Previous FQCN, from before CarpetAvenue_EnhanceCategory was merged into
     * CarpetAvenue_Productify. Magento's PatchApplier checks aliases against
     * patch_list, so returning it here stops this patch re-running on stores
     * that already applied it under the old name.
     */
    public function getAliases(): array
    {
        // String literal, not ::class — the old class no longer exists.
        return [
            'CarpetAvenue\EnhanceCategory\Setup\Patch\Data\AddCategoryImageAttributes',
        ];
    }
}
