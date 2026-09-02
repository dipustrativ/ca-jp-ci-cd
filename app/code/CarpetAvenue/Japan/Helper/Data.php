<?php

namespace CarpetAvenue\Japan\Helper;

use Magento\Store\Model\ScopeInterface;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    const PATH_MOBILE_HIDE = "japan/carpet_finder/mobile_hide";

    /**
     * Retrieve mobile hide
     *
     * @param int|null $storeId
     * @return int
     */
    public function getMobileHide($storeId = null)
    {
        return (int)$this->scopeConfig->getValue(
            self::PATH_MOBILE_HIDE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
