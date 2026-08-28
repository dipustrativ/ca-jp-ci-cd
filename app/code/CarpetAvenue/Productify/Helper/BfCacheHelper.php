<?php

namespace CarpetAvenue\Productify\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class BfCacheHelper extends AbstractHelper
{
    private const XML_PATH_BFCACHE_ENABLED = 'bfcache/general/enabled';
    private const XML_PATH_BFCACHE_MAX_AGE = 'bfcache/general/max_age';

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_BFCACHE_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getMaxAgeSeconds(?int $storeId = null): int
    {
        $value = (int)$this->scopeConfig->getValue(
            self::XML_PATH_BFCACHE_MAX_AGE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        // default 10 minutes
        return $value > 0 ? $value : 600;
    }
}