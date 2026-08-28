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

namespace CarpetAvenue\Productify\Service\Core;

use CarpetAvenue\Productify\Helper\Config;
use CarpetAvenue\Productify\Helper\Data;
use CarpetAvenue\Productify\Model\GroupProductCacheRepository;
use CarpetAvenue\Productify\Model\ProductVariantReferenceRepository;
use Exception;
use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Currency\Exception\CurrencyException;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Locale\Currency as LocaleCurrency;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class CollectionBuilder
{
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
        protected LoggerInterface                   $logger,
        protected ManagerInterface                  $eventManager,
        protected StockResolverInterface            $stockResolver
    ) {
    }

    /**
     * Build Products Specific Store
     *
     * @param $store Store
     * @param array $productIds
     * @param null $designId
     * @return array
     */
    public function buildForStore(Store $store, array $productIds = [], $designId = null): array
    {
        $productsData = [];

        try {
            $storeId = (int)$store->getStoreId();
            $collection = $this->createProductCollection($storeId, $productIds, $designId);
            $currencyCode = $store->getCurrentCurrencyCode();
            $currencySymbol = $this->getCurrencySymbol($currencyCode);

            foreach ($collection as $product) {

                $productOnline  = (int)$product->getData('online');
                $productStatus  = (int)$product->getData('status');
                $basePrice      = (float) $product->getPrice();
                $baseFinalPrice = (float) $product->getFinalPrice();
                $baseSpecial    = (float) $product->getSpecialPrice();
                $minimalPrice   = (float) $product->getData('minimal_price');
                $maximumPrice   = (float) $product->getData('maximal_price');

                if ($minimalPrice > 0 && $minimalPrice < $baseFinalPrice) {
                    $baseFinalPrice = $minimalPrice;
                }

                $dispPrice      = $this->convert($basePrice, $store, $currencyCode);
                $dispMinPrice   = $this->convert($minimalPrice, $store, $currencyCode);
                $dispFinalPrice = $this->convert($baseFinalPrice, $store, $currencyCode);
                $dispSpecial    = $baseSpecial ? $this->convert($baseSpecial, $store, $currencyCode) : 0.0;

                /**
                 * -----------------------------------
                 * IMPORTANT:: If added any new row must be add this row into the carpetavenue_productify_collection table
                 * -----------------------------------
                 */
                $productsData[$product->getId()] = [
                    // Basic info
                    'id'           => $product->getId(),
                    'store_id'     => $storeId,
                    'sku'          => $product->getSku(),
                    'url'          => $product->getProductUrl(),
                    'final_status' => true,
                    'is_salable'   => (int)$product->getData('is_salable'),
                    'online'       => $productOnline,
                    'status'       => $productStatus,

                    // Images
                    'image'        => $this->getProductImageUrl($product, 'product_page_image_small'),
                    'swatch_image' => $this->getProductImageUrl($product, 'swatch_image', 165, 165, ['type' => 'swatch_image']),

                    // Currency context for the numbers below
                    'currency'        => $currencyCode,
                    'currency_symbol' => $currencySymbol,

                    // RAW numbers in display currency (good for frontend math)
                    'price'         => round($dispPrice, 2),
                    'final_price'   => round($dispFinalPrice, 2),
                    'special_price' => $baseSpecial ? round($dispSpecial, 2) : 0.0,
                    'minimum_price' => $minimalPrice ? round($dispMinPrice, 2) : 0.0,
                    'maximum_price' => $maximumPrice ? round($maximumPrice, 2) : 0.0,

                    // Ready-to-render strings
                    'price_formatted'         => $this->format($dispPrice, $store, $currencyCode),
                    'final_price_formatted'   => $this->format($dispFinalPrice, $store, $currencyCode),
                    'special_price_formatted' => $minimalPrice ? $this->format($dispMinPrice, $store, $currencyCode) : '',
                    'minimum_price_formated'  => $baseSpecial ? $this->format($dispSpecial, $store, $currencyCode) : '',

                    // Attribute labels
                    Data::NAME_ATTRIBUTE_CODE => $product->getData(Data::NAME_ATTRIBUTE_CODE),
                    Data::DESIGN_ID_GROUP_ATTRIBUTE_CODE => $product->getData(Data::DESIGN_ID_GROUP_ATTRIBUTE_CODE),
                    Data::COLOR_ATTRIBUTE_CODE           => $product->getAttributeText(Data::COLOR_ATTRIBUTE_CODE),
                    Data::SIZE_ATTRIBUTE_CODE            => $product->getAttributeText(Data::SIZE_ATTRIBUTE_CODE),
                    Data::SHAPE_ATTRIBUTE_CODE           => $product->getAttributeText(Data::SHAPE_ATTRIBUTE_CODE),
                    Data::SHAPE_ATTRIBUTE_KEY_CODE       => $product->getData(Data::SHAPE_ATTRIBUTE_CODE),
                ];

            }

        } catch (Exception $exception) {
            $this->logger->error('Error Building Products', ['exception' => $exception]);
            return $productsData;
        }

        return $productsData;
    }


    /**
     * Create Product Collection for Store
     * @param int $storeId Store Id
     * @param array $productIds Product IDs to filter, empty for all
     * @param null $designId Design ID
     *
     * @throws NoSuchEntityException
     */
    public function createProductCollection(int $storeId, array $productIds = [], $designId = null): Collection
    {
        $collection = $this->collectionFactory->create();
        $collection
            ->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->addAttributeToSelect([
                'sku',
                'name',
                'price',
                'special_price',
                'status',
                'online',
                'url_key',
                'image',
                'thumbnail',
                'swatch_image',
                'swatch_image_base',
                'otto_price',
                Data::DESIGN_ID_GROUP_ATTRIBUTE_CODE,
                Data::COLOR_ATTRIBUTE_CODE,
                Data::SIZE_ATTRIBUTE_CODE,
                Data::SHAPE_ATTRIBUTE_CODE
            ])
            ->addFinalPrice();

        // Filter by product IDs if provided
        if (!empty($productIds)) {
            $collection->addFieldToFilter('entity_id', ['in' => $productIds]);
        }

        if ($designId) {
            $collection->addAttributeToFilter(Data::DESIGN_ID_GROUP_ATTRIBUTE_CODE, $designId);
        }

        // Add stock data - this adds is_in_stock, qty, etc.
        $websiteId = $this->storeManager->getStore($storeId)->getWebsite()->getCode();
        $stockId = $this->stockResolver->execute(
            SalesChannelInterface::TYPE_WEBSITE,
            $websiteId
        )->getStockId();

        // Join MSI stock data
        $collection->getSelect()->joinLeft(
            ['inventory_stock' => $collection->getTable('inventory_stock_' . $stockId)],
            'e.sku = inventory_stock.sku',
            ['is_salable', 'quantity']
        );

        return $collection;
    }


    /**
     * Get currency symbol or fallback to currency code
     *
     * @param string $currencyCode
     * @return string
     * @throws CurrencyException
     */
    private function getCurrencySymbol(string $currencyCode): string
    {
        $symbol = $this->localeCurrency->getCurrency($currencyCode)->getSymbol();
        return $symbol ?: $currencyCode;
    }

    /**
     * Convert amount to store currency
     *
     * @param float $amount
     * @param $store
     * @param string $currencyCode
     * @return float
     */
    private function convert(float $amount, $store, string $currencyCode): float
    {
        return $this->priceCurrency->convert($amount, $store, $currencyCode);
    }

    /**
     * Format amount as currency string
     *
     * @param float $amount
     * @param $store
     * @param string $currencyCode
     * @return string
     */
    private function format(float $amount, $store, string $currencyCode): string
    {
        return $this->priceCurrency->format(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            $store,
            $currencyCode
        );
    }

    /**
     * Get product image URL with resizing
     *
     * @param Product|null $product
     * @param string $imageId
     * @param int $width
     * @param int $height
     * @param array $attribute
     * @return false|string
     */
    public function getProductImageUrl(
        ?Product $product = null,
        string  $imageId = 'thumbnail',
        int     $width = 200,
        int     $height = 200,
        array   $attribute = ['type' => 'thumbnail']
    ): false|string
    {
        if (!$product) {
            return false;
        }
        return $this->imageHelper->init($product, $imageId, $attribute)
            ->constrainOnly(true)
            ->keepAspectRatio(true)
            ->keepTransparency(true)
            ->resize($width, $height)
            ->getUrl();
    }
}