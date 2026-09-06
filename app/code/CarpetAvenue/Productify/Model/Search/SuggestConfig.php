<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\Model\Search;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Settings for the header search suggestion dropdown
 * (Stores > Config > CarpetAvenue > Productify > Search Suggestions).
 */
class SuggestConfig
{
    private const XML_PATH_ENABLED = 'productify/search_suggest/enabled';
    private const XML_PATH_MIN_CHARS = 'productify/search_suggest/min_chars';
    private const XML_PATH_PRODUCT_LIMIT = 'productify/search_suggest/product_limit';
    private const XML_PATH_CACHE_TTL = 'productify/search_suggest/cache_ttl';

    /** Rows per secondary section: categories, suggested searches, CMS pages. */
    public const SIDE_LIMIT = 8;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getMinChars(?int $storeId = null): int
    {
        return max(1, (int) $this->scopeConfig->getValue(
            self::XML_PATH_MIN_CHARS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    public function getProductLimit(?int $storeId = null): int
    {
        return max(1, (int) $this->scopeConfig->getValue(
            self::XML_PATH_PRODUCT_LIMIT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Seconds a rendered fragment stays in the cache. 0 disables caching.
     */
    public function getCacheTtl(?int $storeId = null): int
    {
        return max(0, (int) $this->scopeConfig->getValue(
            self::XML_PATH_CACHE_TTL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }
}
