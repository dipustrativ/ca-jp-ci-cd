<?php

declare(strict_types=1);

namespace Evalent\Banner\Setup\Patch\Data;

use Evalent\Banner\Model\Banner;
use Evalent\Banner\Setup\BannerSetupFactory;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchInterface;

class AddHeadingTagAttribute implements DataPatchInterface
{


    /**
     * @var \Magento\Eav\Setup\EavSetup
     */
    private $eavSetup;

    public function __construct(EavSetup $eavSetup)
    {
        $this->eavSetup = $eavSetup;
    }

    /**
     * Get array of patches that have to be executed prior to this.
     *
     * example of implementation:
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
        return [InstallEntities::class];
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
     * If code fails, patch must be reverted, in case when we are speaking about schema - than under revert
     * means run PatchInterface::revert()
     *
     * If we speak about data, under revert means: $transaction->rollback()
     *
     * @return $this
     */
    public function apply()
    {
        $bannerEntity = Banner::ENTITY;
        $setup = $this->eavSetup;

        /** @var \Evalent\Banner\Setup\BannerSetup $bannerSetup */
        $setup->addAttribute($bannerEntity,'heading_tag', [ 'type' => 'varchar']);
        return $this;
    }
}