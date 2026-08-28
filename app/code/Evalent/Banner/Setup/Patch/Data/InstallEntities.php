<?php

declare(strict_types=1);

namespace Evalent\Banner\Setup\Patch\Data;

use Evalent\Banner\Model\Banner;
use Evalent\Banner\Setup\BannerSetupFactory;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchInterface;

class InstallEntities implements DataPatchInterface
{

    /**
     * @var \Evalent\Banner\Setup\BannerSetupFactory
     */
    private $bannerSetupFactory;

    public function __construct(BannerSetupFactory $bannerSetupFactory)
    {
        $this->bannerSetupFactory = $bannerSetupFactory;
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

        /** @var \Evalent\Banner\Setup\BannerSetup $bannerSetup */
        $bannerSetup =$this->bannerSetupFactory->create();
        $bannerSetup->installEntities();
        $bannerSetup->addAttribute($bannerEntity,'enabled', [ 'type' => 'int']);
        $bannerSetup->addAttribute($bannerEntity,'desktop_image', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'mobile_image', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'heading', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'heading_secondary', [ 'type' => 'text']);
        $bannerSetup->addAttribute($bannerEntity,'heading_tag', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'description', [ 'type' => 'text']);
        $bannerSetup->addAttribute($bannerEntity,'button_text', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'button_enabled_desktop', [ 'type' => 'int']);
        $bannerSetup->addAttribute($bannerEntity,'button_enabled_mobile', [ 'type' => 'int']);
        $bannerSetup->addAttribute($bannerEntity,'button_style', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'button_content_theme', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'content_align_mobile', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'content_align_desktop', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'text_align_mobile', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'text_align_desktop', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'banner_link_url', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'banner_link_url_new_window', [ 'type' => 'int']);
        $bannerSetup->addAttribute($bannerEntity,'button_size_desktop', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'button_size_mobile', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'heading_secondary_size_desktop', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'heading_secondary_size_mobile', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'description_size_desktop', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'description_size_mobile', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'content_custom_color', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'button_text_color', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'shadow_effect', [ 'type' => 'int']);
        $bannerSetup->addAttribute($bannerEntity,'description_position', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'date_start', [ 'type' => 'varchar']);
        $bannerSetup->addAttribute($bannerEntity,'date_end', [ 'type' => 'varchar']);
        return $this;
    }
}
