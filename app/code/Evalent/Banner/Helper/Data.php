<?php

namespace Evalent\Banner\Helper;

use Magento\Store\Model\ScopeInterface;


class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    /**
     * XML config path search delay
     */
    const XML_PATH_QUALITY = 'evalent_banner/evalent_banner_main/quality';

    const XML_PATH_DESKTOP_WIDTH = 'evalent_banner/evalent_banner_main/desktop_width';

    const XML_PATH_DESKTOP_HEIGHT = 'evalent_banner/evalent_banner_main/desktop_height';

    const XML_PATH_MOBILE_WIDTH = 'evalent_banner/evalent_banner_main/mobile_width';

    const XML_PATH_MOBILE_HEIGHT = 'evalent_banner/evalent_banner_main/mobile_height';

    /**
     * Retrieve compression quality
     *
     * @param int|null $storeId
     * @return int
     */
    public function getQuality($storeId = null)
    {
        return (int)$this->scopeConfig->getValue(
            self::XML_PATH_QUALITY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Retrieve desktop resolution width
     *
     * @param int|null $storeId
     * @return int
     */
    public function getDesktopWidth($storeId = null)
    {
        return (int)$this->scopeConfig->getValue(
            self::XML_PATH_DESKTOP_WIDTH,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Retrieve desktop resolution height
     *
     * @param int|null $storeId
     * @return int
     */
    public function getDesktopHeight($storeId = null)
    {
        return (int)$this->scopeConfig->getValue(
            self::XML_PATH_DESKTOP_HEIGHT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Retrieve mobile resolution width
     *
     * @param int|null $storeId
     * @return int
     */
    public function getMobileWidth($storeId = null)
    {
        return (int)$this->scopeConfig->getValue(
            self::XML_PATH_MOBILE_WIDTH,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Retrieve mobile resolution height
     *
     * @param int|null $storeId
     * @return int
     */
    public function getMobileHeight($storeId = null)
    {
        return (int)$this->scopeConfig->getValue(
            self::XML_PATH_MOBILE_HEIGHT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

}
