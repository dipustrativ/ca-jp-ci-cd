<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Model;

use CarpetAvenue\Productify\Helper\Data;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Cache\Type as EavCacheType;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Works out which `shape` option is the catalogue's primary (rectangular) shape
 *
 * Option ids are assigned per database, so none is hardcoded. A configured id is
 * used only when it really is a `shape` option; otherwise the shape most products
 * carry wins. A setting copied from another database, or saved before the field
 * was a dropdown, therefore cannot point the storefront at a foreign option.
 */
class PrimaryShapeResolver
{
    private const CACHE_KEY = 'productify_shape_option_usage';

    /**
     * Product counts drift slowly; the EAV cache tags also clear this on attribute changes.
     */
    private const CACHE_LIFETIME = 86400;

    /**
     * @var array<int|string, int>|null Product count per shape option id, most used first
     */
    private ?array $usage = null;

    /**
     * @param ResourceConnection $resourceConnection
     * @param EavConfig $eavConfig
     * @param CacheInterface $cache
     * @param SerializerInterface $serializer
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EavConfig $eavConfig,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * Primary shape option id
     *
     * @param string $configuredOptionId Admin setting; '' means automatic
     * @return string The configured id when it is a shape option, else the most used
     *                shape option, else $configuredOptionId unchanged (no shape options)
     */
    public function resolve(string $configuredOptionId): string
    {
        $usage = $this->getUsage();
        if ($configuredOptionId !== '' && isset($usage[$configuredOptionId])) {
            return $configuredOptionId;
        }
        return $usage ? (string) array_key_first($usage) : $configuredOptionId;
    }

    /**
     * Product count per shape option id, most used first (ties: lowest id first)
     *
     * Counts default-scope values. Options no product uses are included with 0.
     *
     * @return array<int|string, int>
     */
    public function getUsage(): array
    {
        if ($this->usage !== null) {
            return $this->usage;
        }

        $cached = $this->cache->load(self::CACHE_KEY);
        if ($cached) {
            return $this->usage = $this->serializer->unserialize($cached);
        }

        $this->usage = $this->loadUsage();
        $this->cache->save(
            $this->serializer->serialize($this->usage),
            self::CACHE_KEY,
            [EavCacheType::CACHE_TAG, Attribute::CACHE_TAG],
            self::CACHE_LIFETIME
        );
        return $this->usage;
    }

    /**
     * Count products per shape option straight from the attribute's value table
     *
     * @return array<int|string, int>
     */
    private function loadUsage(): array
    {
        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, Data::SHAPE_ATTRIBUTE_CODE);
        if (!$attribute->getAttributeId()) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['o' => $this->resourceConnection->getTableName('eav_attribute_option')], ['option_id'])
            ->joinLeft(
                ['v' => $attribute->getBackendTable()],
                'v.attribute_id = o.attribute_id AND v.store_id = 0 AND v.value = o.option_id',
                ['products' => new Expression('COUNT(v.value_id)')]
            )
            ->where('o.attribute_id = ?', (int) $attribute->getAttributeId())
            ->group('o.option_id')
            ->order(['products DESC', 'o.option_id ASC']);

        return array_map('intval', $connection->fetchPairs($select));
    }
}
