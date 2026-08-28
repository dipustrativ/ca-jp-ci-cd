<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Setup\Patch\Data;

use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean as BooleanSource;
use Magento\Framework\Validator\ValidateException;

/**
 * @noinspection PhpUnused
 */
class AddIsGroupAttribute implements DataPatchInterface
{
    private ModuleDataSetupInterface $moduleDataSetup;
    private EavSetupFactory $eavSetupFactory;
    private const TARGET_ATTRIBUTE_SET = 'Carpet';
    private const TARGET_ATTRIBUTE_GROUP = 'Carpet';
    private const ATTRIBUTE_CODE = 'is_group';

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        EavSetupFactory $eavSetupFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
    }

    /**
     * @throws ValidateException
     * @throws LocalizedException
     */
    public function apply(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $attrId = $eavSetup->getAttributeId(Product::ENTITY, self::ATTRIBUTE_CODE);
        if (!$attrId) {
            $eavSetup->addAttribute(
                Product::ENTITY,
                self::ATTRIBUTE_CODE,
                [
                    'type'                  => 'int',
                    'label'                 => 'Is Group',
                    'input'                 => 'boolean',
                    'source'                => BooleanSource::class,
                    'global'                => ScopedAttributeInterface::SCOPE_GLOBAL,
                    'visible'               => true,
                    'required'              => false,
                    'user_defined'          => true,
                    'default'               => 0,
                    'searchable'            => false,
                    'filterable'            => false,
                    'comparable'            => false,
                    'visible_on_front'      => false,
                    'used_in_product_listing' => true,
                    'unique'                => false,
                    'apply_to'              => '',
                    'is_used_in_grid'       => 1,
                    'is_visible_in_grid'    => 1,
                    'is_filterable_in_grid' => 1,
                ]
            );
            $eavSetup->getAttributeId(Product::ENTITY, self::ATTRIBUTE_CODE);
        } else {
            // Ensure grid flags are set even if attribute already existed
            $eavSetup->updateAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, 'is_used_in_grid', 1);
            $eavSetup->updateAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, 'is_visible_in_grid', 1);
            $eavSetup->updateAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, 'is_filterable_in_grid', 1);
            $eavSetup->updateAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, 'used_in_product_listing', 1);
        }

        $attributeSetId = $eavSetup->getAttributeSetId(Product::ENTITY, self::TARGET_ATTRIBUTE_SET);
        if ($attributeSetId) {
            $groupId = $eavSetup->getAttributeGroupId(Product::ENTITY, $attributeSetId, self::TARGET_ATTRIBUTE_GROUP);
            if (!$groupId) {
                $eavSetup->addAttributeGroup(
                    Product::ENTITY,
                    $attributeSetId,
                    self::TARGET_ATTRIBUTE_GROUP,
                    90
                );
                $groupId = $eavSetup->getAttributeGroupId(Product::ENTITY, $attributeSetId, self::TARGET_ATTRIBUTE_GROUP);
            }
            $eavSetup->addAttributeToSet(
                Product::ENTITY,
                $attributeSetId,
                $groupId,
                self::ATTRIBUTE_CODE,
                10
            );
        }

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
