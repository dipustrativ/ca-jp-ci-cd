<?php

namespace CarpetAvenue\Productify\Block\Widget;

use Evalent\Banner\Api\BannerRepositoryInterface;
use Evalent\Banner\Api\Data\BannerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\Template;
use Magento\Widget\Block\BlockInterface;
use Evalent\Banner\Model\ResourceModel\Banner\CollectionFactory;

class ShowroomBanner extends Template implements BlockInterface
{
    protected BannerInterface $banner;

    protected $_template = "widget/default_showroom_banner.phtml";

    public function __construct(
        protected CollectionFactory $bannerCollectionFactory,
        protected BannerRepositoryInterface $bannerRepository,
        Template\Context $context,
        array $data = []
    )
    {
        parent::__construct($context, $data);
    }

    protected function _toHtml(): string
    {
        if ($this->getBanner() == null) {
            return '';
        }
        return parent::_toHtml();
    }

    /**
     * @return BannerInterface|null
     */
    public function getBanner(): ?BannerInterface
    {
        if (isset($this->banner)) {
            return $this->banner;
        }

        $bannerId = $this->getData('banner');
        if (!$bannerId) {
            return null;
        }
        try {
            $banner = $this->bannerRepository->get($bannerId);
            if (!$banner->getEnabled()) {
                return null;
            }
            $this->banner = $banner;
            return $this->banner;
        } catch (LocalizedException $e) {
            return null;
        }
    }

}