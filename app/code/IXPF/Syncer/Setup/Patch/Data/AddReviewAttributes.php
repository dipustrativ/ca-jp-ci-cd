<?php

declare(strict_types=1);

namespace IXPF\Syncer\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

/**
 * Review aggregates duplicated onto the product.
 *
 * These duplicate two columns of ixpf_review_group on purpose: they are read inside
 * full-page-cached HTML and in category listing indexes, where a query per product is not
 * affordable. The duplication is why they can briefly disagree with the group table between
 * the attribute push and the upsert — a known and accepted window.
 *
 * Written only by IXPF through the existing AttributeMassUpdate endpoint. Nothing in this
 * module writes them.
 */
class AddReviewAttributes implements DataPatchInterface, PatchRevertableInterface
{
    private const ATTRIBUTES = [
        'review_count' => [
            'type'  => 'int',
            'label' => 'Review Count',
            'input' => 'text',
            'default' => 0,
            'sort_order' => 210,
        ],
        'review_average' => [
            'type'  => 'decimal',
            'label' => 'Review Average',
            'input' => 'text',
            'default' => 0,
            'sort_order' => 220,
        ],
        'review_group' => [
            'type'  => 'varchar',
            'label' => 'Review Group',
            'input' => 'text',
            'default' => null,
            'sort_order' => 230,
        ],
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        foreach (self::ATTRIBUTES as $code => $attribute) {
            $eavSetup->addAttribute(Product::ENTITY, $code, [
                'type'                    => $attribute['type'],
                'label'                   => $attribute['label'],
                'input'                   => $attribute['input'],
                'default'                 => $attribute['default'],
                'sort_order'              => $attribute['sort_order'],
                'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                'required'                => false,
                'user_defined'            => true,
                'visible'                 => true,
                'visible_on_front'        => false,
                'searchable'              => false,
                'filterable'              => false,
                'filterable_in_search'    => false,
                'comparable'              => false,
                'used_for_sort_by'        => false,
                'used_in_product_listing' => true,
                'unique'                  => false,
                'apply_to'                => '',
                // 'group' is what makes EavSetup add the attribute to EVERY attribute set, not
                // just Default — see Magento\Eav\Setup\EavSetup::addAttribute(). A product in a
                // set that lacks these would silently reject the IXPF push.
                'group'                   => 'General',
                'is_used_in_grid'         => true,
                'is_visible_in_grid'      => false,
                'is_filterable_in_grid'   => false,
            ]);
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    public function revert(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        foreach (array_keys(self::ATTRIBUTES) as $code) {
            $eavSetup->removeAttribute(Product::ENTITY, $code);
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
