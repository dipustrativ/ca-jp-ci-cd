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
     * @return array Built group products
     */
    public function buildForStore(Store $store, int $storeId, array $products, ?callable $onProduct = null): array
    {
        try {
            // One collection query per DESIGN, not per product. Every size variant of
            // a rug carries the same design id, so looping $products directly re-ran
            // the same group query (and re-saved the same blob) once per sibling —
            // ~10x the work on this catalog.
            /** @var array<string, array{design_id: string, products: array}> $productsByDesign */
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

                // Prefix the key: PHP casts numeric-string array keys to integers,
                // while the repository contract requires the original string id.
                $designId = (string) $designId;
                $designKey = 'design:' . $designId;
                $productsByDesign[$designKey]['design_id'] = $designId;
                $productsByDesign[$designKey]['products'][] = $product;
            }

            $groups = [];
            foreach ($productsByDesign as $designGroup) {
                $designId = $designGroup['design_id'];
                $designProducts = $designGroup['products'];

                // Build variant data
                $groupProductCollection = $this->collectionBuilder->buildForStore($store, [], $designId);
                $variants = [];
                foreach ($groupProductCollection as $groupProduct) {
                    $groups[] = $groupProduct;
                    $variantData = $this->buildVariantData($groupProduct);
                    if ($variantData) {
                        $variants[] = $variantData;
                    }
                }
                if ($variants) {
                    try {
                        $this->repository->saveByDesignId($designId, ['variants' => $variants], $storeId);
                    } catch (Exception $exception) {
                        $this->logger->error(
                            'Error saving design ID cache ' . $designId
                            . ' for store ID ' . $storeId . ': ' . $exception->getMessage()
                        );
                    }
                }
                if ($onProduct) {
                    for ($i = count($designProducts); $i > 0; $i--) {
                        $onProduct();
                    }
                }
            }

            $this->logger->info(
                "Productify : [Group Builder] Saved " . count($groups) . " groups for store ID $storeId."
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
