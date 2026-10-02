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
use CarpetAvenue\Productify\Service\Core\BuildProgress;
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
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProductSync
{
    protected array $productIds = [];
    protected ?OutputInterface $output = null;

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
     * @param array $productIds Only rebuild these products; empty rebuilds every product
     * @param OutputInterface|null $output CLI output for progress bars
     * @return array
     */
    public function buildAllStores(array $productIds = [], ?OutputInterface $output = null): array
    {
        $this->productIds = $productIds;
        // Progress (store headers, bars and their line breaks) all goes to stderr, where
        // ProgressBar draws anyway: one stream keeps it in order when stdout and stderr
        // are merged, and leaves stdout to the command's result lines.
        $this->output = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $results = [];
        $startTime = microtime(true);
        $scope = $productIds ? count($productIds) . ' products' : 'all products';
        $this->logger->info("Productify : [Queue] Starting build for all stores ($scope).");
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId(); // Do not remove this line
            $storeLabel = "store ID {$storeId} ({$store->getCode()})";
            $storeStart = microtime(true);
            $this->emulation->startEnvironmentEmulation($storeId, 'frontend', true);
            try {
                $this->output?->writeln("<info>Store {$storeId} ({$store->getCode()})</info>");
                $loadProgress = $this->progress('Loading products', $storeLabel);
                $products = $this->collectionBuilder->buildForStore($store, $productIds, null, $loadProgress);
                $loadProgress->finish(count($products));

                // Varient Builder Data
                $this->logger->info("Productify : [Queue] Building variants for store ID {$storeId} with " . count($products) . " products.");
                $variantProgress = $this->progress('Building variants', $storeLabel);
                $variantTotal = count($products);
                $variantDone = 0;
                $variantProgress($variantDone, $variantTotal);
                // A full build already holds every product of the store, so the
                // variant groups are cut from it instead of queried per design.
                $this->variantBuilder->buildForStore(
                    $store,
                    $storeId,
                    $products,
                    static function () use ($variantProgress, &$variantDone, $variantTotal): void {
                        $variantProgress(++$variantDone, $variantTotal);
                    },
                    $productIds ? null : $products
                );
                $variantProgress->finish($variantDone);

                //-- URL rewrites check (a phase only when the feature is enabled)
                if ($this->configHelper->isUpdateProductUrlRewriteEnabled()) {
                    $urlProgress = $this->progress('URL rewrites', $storeLabel);
                    $this->productUrlRewrite->init($products, $urlProgress);
                    $urlProgress->finish(count($products));
                }

                //-- Store productify collection data into db
                $saveProgress = $this->progress('Saving', $storeLabel);
                $saveProgress(0, count($products));
                $failed = $this->productifyCollectionResourceFactory->create()
                    ->saveMany($products, $saveProgress);
                $saveProgress->finish(count($products));
                if ($failed) {
                    $this->logger->warning(
                        "Productify : [Queue] {$storeLabel}: {$failed} of " . count($products)
                        . " products could not be saved to carpetavenue_productify_collection."
                    );
                }

                $this->logger->info(sprintf(
                    'Productify : [Queue] %s done in %.1fs, peak memory %dMB.',
                    $storeLabel,
                    microtime(true) - $storeStart,
                    memory_get_peak_usage(true) / 1048576
                ));

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
     * Progress reporter for one phase of one store
     *
     * @param string $phase
     * @param string $storeLabel
     * @return BuildProgress
     */
    private function progress(string $phase, string $storeLabel): BuildProgress
    {
        return new BuildProgress($this->logger, $this->output, $phase, $storeLabel);
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