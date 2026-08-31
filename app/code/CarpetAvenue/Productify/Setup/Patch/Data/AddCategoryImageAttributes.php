<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Adds the "thumbnail" and "mobile_image" category attributes
 * (ported from Evalent_AdditionalCategoryImages InstallData).
 *
 * NOTE: must live directly under Setup/Patch/Data — Magento's PatchReader only
 * globs <module>/Setup/Patch/Data/*.php and does not recurse into sub-namespace
 * folders, so the previous EnhanceCategory/Setup/Patch/Data location was never
 * discovered and the attributes were never created.
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
     * Previous FQCNs. Magento's PatchApplier checks aliases against patch_list,
     * so returning them here stops this patch re-running on stores that already
     * applied it under an older name:
     *  - the sub-namespace location it lived in after the EnhanceCategory merge
     *    (never discovered, but listed for completeness);
     *  - the pre-merge CarpetAvenue_EnhanceCategory module name.
     */
    public function getAliases(): array
    {
        // String literals, not ::class — the old classes no longer exist.
        return [
            'CarpetAvenue\Productify\EnhanceCategory\Setup\Patch\Data\AddCategoryImageAttributes',
            'CarpetAvenue\EnhanceCategory\Setup\Patch\Data\AddCategoryImageAttributes',
        ];
    }
}
