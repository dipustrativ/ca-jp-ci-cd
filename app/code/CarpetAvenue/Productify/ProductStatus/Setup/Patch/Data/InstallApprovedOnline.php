<?php
namespace CarpetAvenue\Productify\ProductStatus\Setup\Patch\Data;
//use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Action;

class InstallApprovedOnline implements DataPatchInterface {
    private $moduleDataSetup;
    private $eavSetupFactory;
    private CollectionFactory $collectionFactory;
    private Action $action;
    
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        EavSetupFactory $eavSetupFactory,
        CollectionFactory $collectionFactory,
        Action $action
    ){
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
        $this->collectionFactory = $collectionFactory;
        $this->action = $action;
    }

    public function apply(): void
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $eavSetup->addAttribute(
            \Magento\Catalog\Model\Product::ENTITY,
            'approved',
            [
                'group' => 'General',
                'type' => 'int',
                'backend' => '',
                'frontend' => '',
                'label' => 'Approved',
                'input' => 'boolean',
                'class' => '',
                'source' => \Magento\Eav\Model\Entity\Attribute\Source\Boolean::class,
                'global' => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible' => true,
                'required' => false,
                'user_defined' => false,
                'default' => \Magento\Eav\Model\Entity\Attribute\Source\Boolean::VALUE_NO,
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => false,
                'used_in_product_listing' => false,
                'unique' => false,
                'apply_to' => 'simple,configurable,virtual,bundle,downloadable'
            ]
        );

        $eavSetup->addAttribute(
            \Magento\Catalog\Model\Product::ENTITY,
            'online',
            [
                'group' => 'General',
                'type' => 'int',
                'backend' => '',
                'frontend' => '',
                'label' => 'ONLINE',
                'input' => 'boolean',
                'class' => '',
                'source' => \Magento\Eav\Model\Entity\Attribute\Source\Boolean::class,
                'global' => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible' => true,
                'required' => false,
                'user_defined' => false,
                'default' => \Magento\Eav\Model\Entity\Attribute\Source\Boolean::VALUE_NO,
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => false,
                'used_in_product_listing' => false,
                'unique' => false,
                'apply_to' => 'simple,configurable,virtual,bundle,downloadable'
            ]
        );

        //set default value for the new custom attribute
        $productIds = $this->collectionFactory->create()->getAllIds();
        $this->action->updateAttributes($productIds, ['approved' => \Magento\Eav\Model\Entity\Attribute\Source\Boolean::VALUE_YES], 0);

        $productIds = $this->collectionFactory->create()->getAllIds();
        $this->action->updateAttributes($productIds, ['online' => \Magento\Eav\Model\Entity\Attribute\Source\Boolean::VALUE_YES], 0);
    }

    /**
     * {@inheritdoc}
     */
    public static function getDependencies()
    {
        return [];
    }
 
    /**
     * {@inheritdoc}
     */
    /**
     * Previous FQCN, from before CarpetAvenue_ProductStatus was merged into
     * CarpetAvenue_Productify. Magento's PatchApplier checks aliases against
     * patch_list, so returning it here stops this patch re-running on stores
     * that already applied it under the old name.
     */
    public function getAliases()
    {
        // String literal, not ::class — the old class no longer exists.
        return [
            'CarpetAvenue\ProductStatus\Setup\Patch\Data\InstallApprovedOnline',
        ];
    }
}