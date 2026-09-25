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

namespace CarpetAvenue\Productify\Service;

use CarpetAvenue\Productify\Helper\Config;
use CarpetAvenue\Productify\Helper\Data;
use CarpetAvenue\Productify\Helper\Logger;
use CarpetAvenue\Productify\Model\GroupProductCacheRepository;
use CarpetAvenue\Productify\Model\ProductVariantReferenceRepository;
use CarpetAvenue\Productify\Service\Core\CollectionBuilder;
use Exception;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Helper\Image;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Locale\Currency as LocaleCurrency;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;

class VariantBuilder
{
    /**
     * Design ids per sibling query on a partial (queue) build.
     */
    private const DESIGN_QUERY_CHUNK = 500;

    /**
     * @param CollectionFactory $collectionFactory
     * @param GroupProductCacheRepository $repository
     * @param ProductVariantReferenceRepository $referenceRepository
     * @param StoreManagerInterface $storeManager
     * @param Image $imageHelper
     * @param CacheInterface $cache
     * @param Emulation $emulation
     * @param PriceCurrencyInterface $priceCurrency
     * @param LocaleCurrency $localeCurrency
     * @param Config $configHelper
     * @param ManagerInterface $eventManager
     * @param StockResolverInterface $stockResolver
     * @param Logger $logger
     * @param CollectionBuilder $collectionBuilder
     */
    public function __construct(
        protected CollectionFactory                 $collectionFactory,
        protected GroupProductCacheRepository       $repository,
        protected ProductVariantReferenceRepository $referenceRepository,
        protected StoreManagerInterface             $storeManager,
        protected Image                             $imageHelper,
        protected CacheInterface                    $cache,
        protected Emulation                         $emulation,
        protected PriceCurrencyInterface            $priceCurrency,
        protected LocaleCurrency                    $localeCurrency,
        protected Config                            $configHelper,
        protected ManagerInterface                  $eventManager,
        protected StockResolverInterface            $stockResolver,
        protected Logger                            $logger,
        protected CollectionBuilder                 $collectionBuilder
    ) {
    }

    /**
     * Build variant cache for a specific store
     *
     * @param Store $store
     * @param int $storeId
     * @param array $products
     * @param callable|null $onProduct Called once per product, for CLI progress reporting
     * @param array|null $storeProducts Every product of the store, when the caller already
     *                                  holds them (full build). Null loads the siblings of
     *                                  $products' designs instead.
     * @return array Built group products
     */
    public function buildForStore(
        Store $store,
        int $storeId,
        array $products,
        ?callable $onProduct = null,
        ?array $storeProducts = null
    ): array {
        try {
            // One entry per DESIGN, not per product: every size variant of a rug
            // carries the same design id, and each design is saved once.
            /** @var array<string, array{design_ids: array<string, string>, count: int}> $productsByDesign */
            $productsByDesign = [];
            foreach ($products as $product) {
                $designId = $product[Data::DESIGN_ID_GROUP_ATTRIBUTE_CODE] ?? null;
                if (!$designId) {
                    // Still has to tick: the progress bar total counts every product.
                    if ($onProduct) {
                        $onProduct();
                    }
                    continue;
                }

                // The repository contract requires the original string id, and a
                // design spelled two collation-equal ways is saved under both.
                $designId = (string) $designId;
                $designKey = $this->designKey($designId);
                $productsByDesign[$designKey]['design_ids'][$designId] = $designId;
                $productsByDesign[$designKey]['count'] = ($productsByDesign[$designKey]['count'] ?? 0) + 1;
            }

            $siblingsByDesign = $this->groupSiblings($store, $productsByDesign, $storeProducts);

            $groups = [];
            foreach ($productsByDesign as $designKey => $designGroup) {
                $variants = [];
                foreach ($siblingsByDesign[$designKey] ?? [] as $groupProduct) {
                    $groups[] = $groupProduct;
                    $variantData = $this->buildVariantData($groupProduct);
                    if ($variantData) {
                        $variants[] = $variantData;
                    }
                }
                if ($variants) {
                    foreach ($designGroup['design_ids'] as $designId) {
                        try {
                            $this->repository->saveByDesignId($designId, ['variants' => $variants], $storeId);
                        } catch (Exception $exception) {
                            $this->logger->error(
                                'Error saving design ID cache ' . $designId
                                . ' for store ID ' . $storeId . ': ' . $exception->getMessage()
                            );
                        }
                    }
                }
                if ($onProduct) {
                    for ($i = $designGroup['count']; $i > 0; $i--) {
                        $onProduct();
                    }
                }
            }

            $this->logger->info(
                "Productify : [Group Builder] Saved " . count($productsByDesign) . " designs ("
                . count($groups) . " products) for store ID $storeId."
            );
            return $groups;
        } catch (Exception $exception) {
            $this->logger->error(
                'Productify : [Queue] [Group Builder] Error - ' . $exception->getMessage()
            );
            $this->logger->error(
                'Productify : [Queue] [Group Builder] Error File - '
                . $exception->getFile() . ':' . $exception->getLine()
            );
            return [];
        }
    }

    /**
     * Collect every store product that belongs to one of the given designs
     *
     * The old path ran one collection query per design. Each of those filters on an
     * unindexed EAV value, so it scans the whole catalog: thousands of designs times
     * the full catalog. A full build already holds every store product, so the groups
     * are cut from that in memory; a partial build loads just the siblings it needs,
     * DESIGN_QUERY_CHUNK designs per query.
     *
     * @param Store $store
     * @param array $productsByDesign Design key => ['design_ids' => [...], 'count' => int]
     * @param array|null $storeProducts
     * @return array Products per design key, in entity_id order
     */
    private function groupSiblings(Store $store, array $productsByDesign, ?array $storeProducts): array
    {
        if ($storeProducts === null) {
            $designIds = array_merge(...array_values(array_column($productsByDesign, 'design_ids')));
            $storeProducts = [];
            foreach (array_chunk($designIds, self::DESIGN_QUERY_CHUNK) as $chunk) {
                $storeProducts += $this->collectionBuilder->buildForStore($store, [], $chunk);
            }
        }

        $siblingsByDesign = [];
        foreach ($storeProducts as $product) {
            $designId = $product[Data::DESIGN_ID_GROUP_ATTRIBUTE_CODE] ?? null;
            if (!$designId) {
                continue;
            }
            $designKey = $this->designKey((string) $designId);
            if (isset($productsByDesign[$designKey])) {
                $siblingsByDesign[$designKey][] = $product;
            }
        }
        return $siblingsByDesign;
    }

    /**
     * Grouping key for a design id, matching how the database compares them
     *
     * The per-design query this replaces matched with the column's utf8mb4_general_ci
     * collation: case-insensitive, trailing spaces ignored. Grouping in PHP has to
     * treat 'D10', 'd10' and 'D10 ' as one design too, or each spelling would save a
     * partial variant list over the same cache row. Prefixed so a numeric id never
     * becomes an integer array key.
     *
     * @param string $designId
     * @return string
     */
    private function designKey(string $designId): string
    {
        return 'design:' . mb_strtolower(rtrim($designId, ' '));
    }

    /**
     * Build variant data for a single product
     *
     * @param array $product
     * @return array|null
     */
    protected function buildVariantData(array $product): ?array
    {
        $productOnline = (int)$product['online'];
        $productStatus = (int)$product['status'];

        if ($productOnline !== 1 || $productStatus !== 1) {
            return null;
        }

        return $product;
    }
}
