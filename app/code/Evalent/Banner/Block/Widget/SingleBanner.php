<?php

declare(strict_types=1);

namespace Evalent\Banner\Block\Widget;

use Evalent\Banner\Api\Data\BannerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\Template;
use Magento\Widget\Block\BlockInterface;

/**
 * Class SingleBanner
 *
 * @method getMarginTopDesktop(): string
 * @method getMarginBottomDesktop(): string
 * @method getMarginTopMobile(): string
 * @method getMarginBottomMobile(): string
 * @method getDisplayMode(): string
 * @api
 */
class SingleBanner extends BannerBlock
{
    const LOGGER_PREFIX = 'SingleBanner Block: ';

    /** @var BannerInterface|null */
    protected $banner;

    protected $_template = "widget/singlebanner.phtml";

    protected function _toHtml(): string
    {
        if ($this->getBanner() == null) {
            return '';
        }
        return parent::_toHtml();
    }


    /**
     * @return \Evalent\Banner\Api\Data\BannerInterface|null
     */
    public function getBanner(): ?BannerInterface
    {
        if ($this->banner != null) {
            return $this->banner;
        }
        $bannerId = $this->getData('banner');
        if (!$bannerId) {
            return null;
        }
        try {
            $banner = $this->bannerRepository->get($bannerId);
            if (!$banner->getEnabled()) {
                $this->logger->info(self::LOGGER_PREFIX . "Banner with ID " . $this->getData('banner') . " is disabled and will not show.");
                return null;
            }
            $this->banner = $banner;
            return $this->banner;
        } catch (LocalizedException $e) {
            $this->logger->error(self::LOGGER_PREFIX . "Couldn't find banner with ID " . $this->getData('banner'));
            return null;
        }
    }
}
