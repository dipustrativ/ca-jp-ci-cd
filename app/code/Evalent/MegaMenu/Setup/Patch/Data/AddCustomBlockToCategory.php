<?php

declare(strict_types=1);

namespace Evalent\MegaMenu\Setup\Patch\Data;

use Evalent\MegaMenu\Model\Config\Source\AdditionalData;
use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\Backend\ArrayBackend;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use Magento\Eav\Setup\EavSetupFactory;

class AddCustomBlockToCategory implements DataPatchInterface, PatchRevertableInterface
{

    /**
     * @var \Magento\Framework\Setup\ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var \Magento\Eav\Setup\EavSetupFactory
     */
    private $eavSetupFactory;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        EavSetupFactory $eavSetupFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
    }

    /**
     * Get array of patches that have to be executed prior to this.
     *
     * Example of implementation:
     *
     * [
     *      \Vendor_Name\Module_Name\Setup\Patch\Patch1::class,
     *      \Vendor_Name\Module_Name\Setup\Patch\Patch2::class
     * ]
     *
     * @return string[]
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * Get aliases (previous names) for the patch.
     *
     * @return string[]
     */
    public function getAliases()
    {
        return [];
    }

    /**
     * Run code inside patch
     * If code fails, patch must be reverted, in case when we are speaking about schema - then under revert
     * means run PatchInterface::revert()
     *
     * If we speak about data, under revert means: $transaction->rollback()
     *
     * @return $this
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $eavSetup->addAttribute(
            Category::ENTITY,
            'custom_block',
            [
                'type' => 'varchar',
                'label' => 'A custom block which is displayed in the mega menu',
                'input' => 'select',
                'source' => '',
                'required' => false,
                'sort_order' => 1,
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'group' => 'Display Settings',
            ]
        );

        $eavSetup->addAttribute(
            Category::ENTITY,
            'custom_url',
            [
                'type' => 'varchar',
                'label' => 'A custom URL which will be used instead of the category url',
                'input' => 'text',
                'source' => '',
                'required' => false,
                'sort_order' => 1,
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'group' => 'Display Settings',
            ]
        );

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * Rollback all changes, done by this patch
     *
     * @return void
     */
    public function revert()
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $eavSetup->removeAttribute(Category::ENTITY, 'additional_megamenu_data');

        $this->moduleDataSetup->getConnection()->endSetup();
    }
}