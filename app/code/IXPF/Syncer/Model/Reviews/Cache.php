<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\Reviews;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

/**
 * Cache type for the review read service.
 *
 * Registering a type is what makes `bin/magento cache:clean` reach these entries at all. Before
 * this existed they were tagged only with the per-pool tag below, which belongs to no cache type,
 * so every `cache:clean` skipped them and only a full `cache:flush` cleared them - the drawer kept
 * serving stale reviews for the provider's 24h lifetime and there was no way to tell it not to.
 *
 * Both tags are written on every entry: CACHE_TAG is the type tag that cache:clean and the admin
 * Cache Management page match, GROUP_TAG_PREFIX is the precise one a push or a media download uses
 * to drop a single pool without touching the rest.
 */
class Cache extends TagScope
{
    public const TYPE_IDENTIFIER = 'ixpf_reviews';
    public const CACHE_TAG = 'IXPF_REVIEWS';

    /** One tag per review pool. Moved here from UpsertReviewGroup - tags belong with the type. */
    public const GROUP_TAG_PREFIX = 'ixpf_review_group_';

    public function __construct(FrontendPool $cacheFrontendPool)
    {
        parent::__construct($cacheFrontendPool->get(self::TYPE_IDENTIFIER), self::CACHE_TAG);
    }

    public static function groupTag(string $reviewGroup): string
    {
        return self::GROUP_TAG_PREFIX . md5($reviewGroup);
    }
}
