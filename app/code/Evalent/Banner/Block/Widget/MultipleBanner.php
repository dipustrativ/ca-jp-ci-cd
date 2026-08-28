<?php

declare(strict_types=1);

namespace Evalent\Banner\Block\Widget;

use Evalent\Banner\Api\BannerRepositoryInterface;
use Evalent\Banner\Api\Data\BannerInterface;
use Evalent\Banner\Api\Data\BannerItemInterface;
use Evalent\Banner\Api\Data\BannerItemInterfaceFactory;
use Evalent\Banner\Model\BannerItem;
use Evalent\Banner\Model\ResourceModel\Banner\CollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\Template;
use Magento\Widget\Block\BlockInterface;
use Psr\Log\LoggerInterface;

/**
 * Class MultipleBanner
 *
 * @method getGutterDesktop(): string
 * @method getMarginTopDesktop(): string
 * @method getMarginBottomDesktop(): string
 * @method getGutterMobile(): string
 * @method getMarginTopMobile(): string
 * @method getMarginBottomMobile(): string
 * @method getDisplayMode(): string
 */
class MultipleBanner extends BannerBlock
{

    const LOGGER_PREFIX = 'MultipleBanner Block: ';

    /**
     * @var \Evalent\Banner\Api\Data\BannerItemInterfaceFactory
     */
    private $bannerItemInterfaceFactory;

    public function __construct(
        BannerItemInterfaceFactory $bannerItemInterfaceFactory,
        LoggerInterface $logger,
        CollectionFactory $bannerCollectionFactory,
        BannerRepositoryInterface $bannerRepository,
        Template\Context $context,
        array $data = []
    ) {
        parent::__construct($logger, $bannerCollectionFactory, $bannerRepository, $context, $data);
        $this->bannerItemInterfaceFactory = $bannerItemInterfaceFactory;
    }

    protected $_template = "widget/multiplebanner.phtml";

    protected function _toHtml(): string
    {
        if (sizeof($this->getBanners()) == 0) {
            return '';
        }
        return parent::_toHtml();
    }

    /**
     * @return BannerItemInterface[]
     * @api
     */
    public function getBanners(): array
    {
        $banners = $this->getData('banner_list');
        $bannersData = json_decode(base64_decode($banners), true);
        $bannerList = [];
        foreach ($bannersData as $data) {
            try {
                $banner = $this->bannerRepository->get($data['banner']);
                if (!$banner->getEnabled()) {
                    continue;
                }
            } catch (LocalizedException $e) {
                $this->logger->error(self::LOGGER_PREFIX . "Could not find banner with ID " . $data['banner']);
                continue;
            }
            /** @var BannerItemInterface $newBanner */
            $newBanner = $this->bannerItemInterfaceFactory->create();
            $newBanner->setAnimationEffect($data['animationEffect']);
            $newBanner->setColumn($data['column']);
            $newBanner->setEntityId($data['banner']);
            $bannerList[] = $newBanner;
        }
        return $bannerList;
    }
}
