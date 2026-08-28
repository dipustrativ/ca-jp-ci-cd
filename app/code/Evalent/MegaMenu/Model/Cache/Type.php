<?php

namespace Evalent\MegaMenu\Model\Cache;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

class Type extends TagScope
{

    /**
     * Cache Identifier
     */
    const TYPE_IDENTIFIER = 'carpetavenue_top_menu_cache';

    /**
     * Tag of Cache
     */
    const CACHE_TAG = 'CARPETAVENUE_TOP_MENU';

    /**
     * @param FrontendPool $cacheFrontendPool
     */
    public function __construct(
        FrontendPool $cacheFrontendPool
    ){
        parent::__construct(
            $cacheFrontendPool->get(self::TYPE_IDENTIFIER),
            self::CACHE_TAG
        );
    }
}