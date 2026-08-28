<?php

namespace Evalent\MegaMenu\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Validator\ValidateException;

class AddMenuColorPickerAttribute implements DataPatchInterface
{
    private ModuleDataSetupInterface $moduleDataSetup;
    private EavSetupFactory $eavSetupFactory;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        EavSetupFactory $eavSetupFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
    }

    /**
     * @throws LocalizedException
     * @throws ValidateException
     */
    public function apply(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        // Background color attribute
        $eavSetup->addAttribute(
            Category::ENTITY,
            'background_color',
            [
                'type' => 'varchar',
                'label' => 'Background Color',
                'input' => 'text',
                'required' => false,
                'sort_order' => 100,
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'group' => 'Display Settings',
                'visible' => true,
                'default' => '',
            ]
        );
        // Foreground color attribute
        $eavSetup->addAttribute(
            Category::ENTITY,
            'foreground_color',
            [
                'type' => 'varchar',
                'label' => 'Foreground Color',
                'input' => 'text',
                'required' => false,
                'sort_order' => 101,
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'group' => 'Display Settings',
                'visible' => true,
                'default' => '',
            ]
        );
        $this->moduleDataSetup->getConnection()->endSetup();
    }

    public static function getDependencies(): array
    {
        return [];
    }
    public function getAliases(): array
    {
        return [];
    }
}
