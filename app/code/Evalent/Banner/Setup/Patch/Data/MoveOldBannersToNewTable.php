<?php

declare(strict_types=1);

namespace Evalent\Banner\Setup\Patch\Data;

use Evalent\Banner\Api\BannerRepositoryInterface;
use Evalent\Banner\Api\Data\BannerInterfaceFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchInterface;

class MoveOldBannersToNewTable implements DataPatchInterface
{


    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var \Evalent\Banner\Api\Data\BannerInterfaceFactory
     */
    private $bannerInterfaceFactory;

    /**
     * @var \Evalent\Banner\Api\BannerRepositoryInterface
     */
    private $bannerRepository;

    /**
     * @var \Magento\Framework\App\State
     */
    private $state;

    public function __construct(
        State $state,
        ResourceConnection $resourceConnection,
        BannerInterfaceFactory $bannerInterfaceFactory,
        BannerRepositoryInterface $bannerRepository
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->bannerInterfaceFactory = $bannerInterfaceFactory;
        $this->bannerRepository = $bannerRepository;
        $this->state = $state;
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
        return [
            InstallEntities::class,
            AddHeadingSizeAttribute::class,
            AddHeadingTagAttribute::class
        ];
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
        $this->state->emulateAreaCode('adminhtml', function (){
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('evalent_banner_banner');
            if (!$connection->isTableExists($tableName)) {
                return $this;
            }


            $sql = "SELECT * from $tableName";
            $oldBanners = $connection->fetchAll($sql);

            foreach ($oldBanners as $oldBanner) {
                $newBanner = $this->bannerInterfaceFactory->create();
                $newBanner->setData($oldBanner);
                try {
                    $this->bannerRepository->save($newBanner);
                } catch (LocalizedException $e) {
                    continue;
                }
            }

        });

        return $this;
    }
}