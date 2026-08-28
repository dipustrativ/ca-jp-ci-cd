<?php

namespace Evalent\MegaMenu\Model\Cache;

use Magento\Store\Model\StoreManagerInterface;

/**
 * Per-store cache for the rendered mega menu.
 *
 * Goes through the module's own cache type (TagScope on CARPETAVENUE_TOP_MENU) instead of
 * App\CacheInterface, so Admin -> Cache Management -> Refresh, `cache:clean
 * carpetavenue_top_menu_cache` and `cache:flush` all hit the scope this class writes to.
 * Type wraps an AccessProxy that no-ops while the type is disabled — hence no isEnabled()
 * guards here.
 */
class TopMenuCache
{
    /** Never null — null means INFINITE (Zend/Cache/Core.php:343), which froze the menu. */
    private const LIFETIME = 86400;

    public function __construct(
        private readonly Type                  $_cache,
        private readonly StoreManagerInterface $_storeManager
    )
    {
    }

    private function getKey(): string
    {
        $storeId = $this->_storeManager->getStore()->getStoreId();
        return Type::TYPE_IDENTIFIER . "_store_" . $storeId;
    }

    public function save($data, $lifetime = self::LIFETIME): bool
    {
        //-- menu save into cache
        return $this->_cache->save($data, $this->getKey(), [Type::CACHE_TAG], $lifetime);
    }

    public function get(): false|string
    {
        return $this->_cache->load($this->getKey());
    }

    /**
     * Drops every store's menu, not just the current one — the tag is shared.
     */
    public function clean(): bool
    {
        return $this->_cache->clean();
    }
}
