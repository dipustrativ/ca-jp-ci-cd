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

namespace CarpetAvenue\Productify\Model;

use CarpetAvenue\Productify\Helper\Data;
use CarpetAvenue\Productify\Model\ResourceModel\GroupProductCache as Resource;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;

class GroupProductCacheRepository
{
    const CACHE_TAG = 'productify_group_variant';
    const CACHE_LIFETIME = 86400;

    /**
     * Marker stored for design groups that have no row, so a miss is answered
     * from cache instead of hitting the table again. Every write goes through
     * saveByDesignId(), which overwrites the key, so a marker cannot outlive
     * the build that fills the gap.
     */
    private const CACHE_MISS_MARKER = '0';

    /**
     * Per-request results, keyed by cache key. A category page renders one tile
     * per product and several tiles can share a design group, so without this
     * the same lookup runs once per tile.
     *
     * @var array<string, array|null>
     */
    private array $loaded = [];

    protected GroupProductCacheFactory $factory;
    protected Resource $resource;
    protected CacheInterface $cache;
    protected Json $serializer;

    public function __construct(
        GroupProductCacheFactory $factory,
        Resource $resource,
        CacheInterface $cache,
        Json $serializer
    ) {
        $this->factory = $factory;
        $this->resource = $resource;
        $this->cache = $cache;
        $this->serializer = $serializer;
    }

    /**
     * @throws AlreadyExistsException|LocalizedException
     */
    public function saveByDesignId(string $designId, array $data, int $storeId): void
    {
        $connection = $this->resource->getConnection();
        $tableName = $this->resource->getMainTable();

        $jsonData = $this->serializer->serialize($data);

        $connection->insertOnDuplicate(
            $tableName,
            [
                Data::DESIGN_ID_GROUP_ATTRIBUTE_CODE => $designId,
                'store_id' => $storeId,
                'json_data' => $jsonData,
                'updated_at' => date('Y-m-d H:i:s')
            ],
            ['json_data', 'updated_at']
        );

        $cacheKey = $this->getCacheKey($designId, $storeId);
        $tags = [self::CACHE_TAG, $this->getStoreCacheTag($storeId)];
        $this->cache->save($jsonData, $cacheKey, $tags, self::CACHE_LIFETIME);
        $this->loaded[$cacheKey] = $data;
    }

    public function getByDesignId(string $designId, int $storeId): ?array
    {
        $cacheKey = $this->getCacheKey($designId, $storeId);

        if (array_key_exists($cacheKey, $this->loaded)) {
            return $this->loaded[$cacheKey];
        }

        $cachedData = $this->cache->load($cacheKey);

        if ($cachedData === self::CACHE_MISS_MARKER) {
            return $this->loaded[$cacheKey] = null;
        }

        if ($cachedData) {
            return $this->loaded[$cacheKey] = $this->serializer->unserialize($cachedData);
        }

        $connection = $this->resource->getConnection();
        $tableName = $this->resource->getTable("carpetavenue_productify_group_product_cache");

        $select = $connection->select()
            ->from($tableName, ['json_data'])
            ->where('design_id_group = ?', $designId)
            ->where('store_id = ?', $storeId)
            ->limit(1);

        $result = $connection->fetchOne($select);
        $tags = [self::CACHE_TAG, $this->getStoreCacheTag($storeId)];

        if ($result) {
            $this->cache->save($result, $cacheKey, $tags, self::CACHE_LIFETIME);
            return $this->loaded[$cacheKey] = $this->serializer->unserialize($result);
        }

        // Cache the miss too: until the variant cache is built, every tile of an
        // unbuilt design group would otherwise re-run this select on every
        // request (52 tiles = 52 queries per full-page-cache miss).
        $this->cache->save(self::CACHE_MISS_MARKER, $cacheKey, $tags, self::CACHE_LIFETIME);

        return $this->loaded[$cacheKey] = null;
    }

    public function getStoreCacheTag(int $storeId): string
    {
        return self::CACHE_TAG . '_' . $storeId;
    }

    private function getCacheKey(string $designId, int $storeId): string
    {
        return self::CACHE_TAG . '_key_' . $designId . '_' . $storeId;
    }
}
