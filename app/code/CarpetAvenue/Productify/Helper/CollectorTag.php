<?php
declare(strict_types=1);

namespace CarpetAvenue\Productify\Helper;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Flags products that sit in the "Collectors" category (or any of its
 * descendants) so the product tile can render the COLLECTORS badge.
 *
 * Resolved in three unbounded queries, then held in the shared cache and in
 * memory — this is called once per product tile.
 */
class CollectorTag extends AbstractHelper
{
    /**
     * Category name to match, lower-cased.
     */
    private const COLLECTOR_CATEGORY_NAME = 'collectors';

    private const CACHE_KEY = 'productify_collector_product_ids';

    /** Backstop only — the catalog cache tags do the real invalidation. */
    private const CACHE_LIFETIME = 3600;

    /**
     * Product ids in the Collectors subtree, as an id => true lookup.
     *
     * @var array<int, true>|null
     */
    private ?array $collectorProductIds = null;

    private ?int $nameAttributeId = null;

    public function __construct(
        Context $context,
        protected ResourceConnection $resource,
        private readonly EavConfig $eavConfig,
        private readonly CacheInterface $cache,
        private readonly Json $serializer
    ) {
        parent::__construct($context);
    }

    /**
     * Check if product belongs to the Collectors category (direct or parent).
     */
    public function isCollectorProduct(int $productId): bool
    {
        if ($this->collectorProductIds === null) {
            $cached = $this->cache->load(self::CACHE_KEY);
            $decoded = ($cached === false || $cached === null) ? null : $this->serializer->unserialize($cached);

            if (is_array($decoded)) {
                $this->collectorProductIds = $decoded;
            } else {
                $this->collectorProductIds = $this->loadCollectorProductIds();
                // cat_c: category renamed/moved. cat_p: assignment changed.
                $this->cache->save(
                    $this->serializer->serialize($this->collectorProductIds),
                    self::CACHE_KEY,
                    [Category::CACHE_TAG, Product::CACHE_TAG],
                    self::CACHE_LIFETIME
                );
            }
        }

        return isset($this->collectorProductIds[$productId]);
    }

    /**
     * @return array<int, true>
     */
    private function loadCollectorProductIds(): array
    {
        $connection = $this->resource->getConnection();
        $categoryTable = $this->resource->getTableName('catalog_category_entity');

        // 1. Categories literally named "Collectors", in any store scope.
        $roots = $connection->fetchPairs(
            $connection->select()
                ->distinct(true)
                ->from(['e' => $categoryTable], ['entity_id', 'path'])
                ->join(
                    ['v' => $this->resource->getTableName('catalog_category_entity_varchar')],
                    'v.entity_id = e.entity_id',
                    []
                )
                ->where('v.attribute_id = ?', $this->getNameAttributeId())
                ->where('LOWER(v.value) = ?', self::COLLECTOR_CATEGORY_NAME)
        );

        if (!$roots) {
            return [];
        }

        // 2. Those categories plus every descendant. A product assigned to any
        //    of them is exactly a product whose assigned category has a
        //    "Collectors" category somewhere in its path.
        $subtreeConditions = [];
        foreach ($roots as $path) {
            $subtreeConditions[] = $connection->quoteInto('path LIKE ?', $path . '/%');
        }
        $subtreeConditions[] = $connection->quoteInto(
            'entity_id IN (?)',
            array_map('intval', array_keys($roots))
        );

        $categoryIds = $connection->fetchCol(
            $connection->select()
                ->from($categoryTable, ['entity_id'])
                ->where(implode(' OR ', $subtreeConditions))
        );

        if (!$categoryIds) {
            return [];
        }

        // 3. Everything assigned anywhere in that subtree.
        $productIds = $connection->fetchCol(
            $connection->select()
                ->distinct(true)
                ->from($this->resource->getTableName('catalog_category_product'), ['product_id'])
                ->where('category_id IN (?)', $categoryIds)
        );

        return array_fill_keys(array_map('intval', $productIds), true);
    }

    /**
     * Get category name attribute ID.
     */
    private function getNameAttributeId(): int
    {
        if ($this->nameAttributeId === null) {
            $this->nameAttributeId = (int)$this->eavConfig
                ->getAttribute(Category::ENTITY, 'name')
                ->getAttributeId();
        }

        return $this->nameAttributeId;
    }
}
