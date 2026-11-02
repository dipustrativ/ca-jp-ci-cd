<?php

declare(strict_types=1);

namespace IXPF\Syncer\Services\Reviews;

use IXPF\Syncer\Model\Reviews\Cache;
use Magento\Framework\App\CacheInterface;

/**
 * The one place review cache entries are dropped.
 *
 * Every writer to the review tables has to call this, not clean a tag itself: the media consumer
 * used to update thumbnail_local and local_path without any invalidation, so photos landing from
 * the queue after a push stayed invisible until the entry aged out 24 hours later.
 */
class ReviewCacheCleaner
{
    public function __construct(
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * Drops one pool. Used after any write that changes what a pool renders.
     */
    public function cleanGroup(string $reviewGroup): void
    {
        if ($reviewGroup === '') {
            return;
        }

        $this->cache->clean([Cache::groupTag($reviewGroup)]);
    }

    /**
     * Drops every pool. Behind the admin button and equivalent to `cache:clean ixpf_reviews`.
     */
    public function cleanAll(): void
    {
        $this->cache->clean([Cache::CACHE_TAG]);
    }
}
