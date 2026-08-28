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
use CarpetAvenue\Productify\Helper\Logger;
use CarpetAvenue\Productify\Model\GroupProductCacheRepository;
use CarpetAvenue\Productify\Model\ProductVariantReferenceRepository;
use CarpetAvenue\Productify\Service\Core\CollectionBuilder;
use Exception;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Helper\Image;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Locale\Currency as LocaleCurrency;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Event\ManagerInterface;
use CarpetAvenue\Productify\Model\ResourceModel\ProductifyCollectionFactory as ProductifyCollectionResourceFactory;

class ProductSync
{
    protected array $productIds = [];
    protected mixed $output;

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
        protected CollectionBuilder                 $collectionBuilder,
        protected Logger                            $logger,
        protected VariantBuilder                    $variantBuilder,
        protected ProductUrlRewrite                 $productUrlRewrite,
        protected ProductifyCollectionResourceFactory $productifyCollectionResourceFactory
    ) {
    }

    /**
     * Sync all Store Products
     *
     * @param array $productIds
     * @param null $output
     * @return array
     */
    public function buildAllStores(array $productIds = [], $output = null): array
    {
        $this->productIds = $productIds;
        $this->output = ($output) ?: null;
        $results = [];
        $startTime = microtime(true);
        $this->logger->info("Productify : [Queue] Starting build for all stores with " . count($productIds) . " products.");
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId(); // Do not remove this line
            $this->emulation->startEnvironmentEmulation($storeId, 'frontend', true);
            try {
                $products = $this->collectionBuilder->buildForStore($store, $productIds);
                // Varient Builder Data
                $this->logger->info("Productify : [Queue] Building variants for store ID {$storeId} with " . count($products) . " products.");
                $this->variantBuilder->buildForStore($store, $storeId, $products);

                //-- URL rewrites check
                $this->productUrlRewrite->init($products);

                //-- Store productify collection data into db
                $productifyCollectionFactory = $this->productifyCollectionResourceFactory->create();
                foreach ($products as $product) {
                    $productifyCollectionFactory->updateOrCreate($product);
                }

                // Callers (productify:product:sync, productify:group-variant:build)
                // array_sum() this, so it has to carry the per-store counts.
                $results[$storeId] = count($products);
            } catch (Exception $exception) {
                $this->logger->error("Productify : [Queue] Error - " . $exception->getMessage());
                $this->logger->error("Productify : [Queue] File - " . $exception->getFile().":".$exception->getLine());
            } finally {
                $this->emulation->stopEnvironmentEmulation();
            }
        }
        $executionTime = round(microtime(true) - $startTime, 2);
        $this->logger->info("Productify : [Queue] Completed build for all stores | Execution took {$executionTime}s");
        return $results;
    }

    /**
     * Invalidate cache tags for affected products
     *
     * @param array $productIds
     * @return void
     */
    private function invalidateProductCaches(array $productIds): void
    {
        if (empty($productIds)) {
            return;
        }

        foreach ($productIds as $productId) {
            // This triggers Magento's built-in cache invalidation
            $this->eventManager->dispatch('clean_cache_by_tags', [
                'tags' => [Product::CACHE_TAG . '_' . $productId]
            ]);
        }
    }
}