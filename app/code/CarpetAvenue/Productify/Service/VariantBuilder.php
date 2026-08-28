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
    private array $watchedAttributes = [
        'final_price',
        'status',
        'online',
        'qty',
        'is_saleable',
        Data::COLOR_ATTRIBUTE_CODE,
        Data::SIZE_ATTRIBUTE_CODE,
        Data::SHAPE_ATTRIBUTE_CODE
    ];

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
     * @param $store Store
     * @param int $storeId
     * @param array $products
     * @return array Design ID => Variants data
     */
    public function buildForStore(Store $store, int $storeId, array $products): array
    {
        try {
            $groups = [];
            foreach ($products as $product) {
                $designId = $product[Data::DESIGN_ID_GROUP_ATTRIBUTE_CODE] ?? null;
                if (!$designId) {
                    continue;
                }

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
                        $this->logger->error('Error saving design ID cache'.' '.$designId.' for store ID '.$storeId.': '.$exception->getMessage());
                    }
                }
            }

            $this->logger->info("Productify : [Group Builder] Saved " . count($groups) . " groups for store ID $storeId.");
            return $groups;
        } catch (Exception $exception) {
            $this->logger->error('Productify : [Queue] [Group Builder] Error - '.$exception->getMessage());
            $this->logger->error('Productify : [Queue] [Group Builder] Error File - ".'.$exception->getFile().":".$exception->getLine());
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