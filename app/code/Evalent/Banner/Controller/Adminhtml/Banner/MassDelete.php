<?php

declare(strict_types=1);

namespace Evalent\Banner\Controller\Adminhtml\Banner;

use Evalent\Banner\Api\BannerRepositoryInterface;
use Evalent\Banner\Model\Banner;
use Evalent\Banner\Model\ResourceModel\Banner\CollectionFactory;

class MassDelete extends MassAction
{

    /**
     * @param Banner $banner
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function massAction(Banner $banner)
    {
        $this->bannerRepository->delete($banner->getDataModel());
        return $this;
    }
}