<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Model\Blacklist;

use Magento\Catalog\Model\Product as ProductModel;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;

/**
 * Purge a product's cached pages after its blocklist state changes.
 *
 * The blocklist is written with a raw insert/delete, which invalidates nothing,
 * so a full-page-cached PDP keeps serving its old response until the product's
 * cache tag is cleaned. Dispatching clean_cache_by_tags routes the purge
 * through core's FlushCacheByTags observer — the same path a product save uses,
 * so it works with the built-in FPC and Varnish alike (a raw backend clean by
 * tag throws on cache backends that only support ALL/OLD, e.g. this store's).
 */
class CachePurger
{
    public function __construct(
        private readonly ProductResource $productResource,
        private readonly EventManager $eventManager
    ) {
    }

    public function purgeBySku(string $sku): void
    {
        $productId = $this->productResource->getIdBySku($sku);
        if (!$productId) {
            return;
        }

        $tags = [ProductModel::CACHE_TAG . '_' . $productId];
        $object = new class ($tags) implements IdentityInterface {
            public function __construct(private readonly array $identities)
            {
            }

            public function getIdentities(): array
            {
                return $this->identities;
            }
        };

        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $object]);
    }
}
