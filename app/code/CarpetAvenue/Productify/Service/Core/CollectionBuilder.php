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
use Magento\Framework\DB\Select;
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

/**
 * Builds the store-scoped product data persisted by Productify.
 */
class CollectionBuilder
{
    /**
     * Products loaded per query. A full build walks the whole catalog; paging keeps
     * one page of product models in memory instead of every product at once.
     */
    private const PAGE_SIZE = 1000;

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
     * @param LoggerInterface $logger
     * @param ManagerInterface $eventManager
     * @param StockResolverInterface $stockResolver
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
        protected LoggerInterface                   $logger,
        protected ManagerInterface                  $eventManager,
        protected StockResolverInterface            $stockResolver
    ) {
    }

    /**
     * Build Products Specific Store
     *
     * @param Store $store
     * @param array $productIds
     * @param string|string[]|null $designId One design id, or several (matched with IN)
     * @param callable|null $onProgress Called as ($done, $total): once with 0 before the
     *                                  first page, then after every product
     * @return array
     */
    public function buildForStore(
        Store $store,
        array $productIds = [],
        string|array|null $designId = null,
        ?callable $onProgress = null
    ): array {
        $productsData = [];
        if ($designId === []) {
            return $productsData;
        }

        try {
            $storeId = (int)$store->getStoreId();
            $total = (int)$this->createProductCollection($storeId, $productIds, $designId)->getSize();
            $currencyCode = $store->getCurrentCurrencyCode();
            $currencySymbol = $this->getCurrencySymbol($currencyCode);
            // Applied here, not left to the HideZeroDecimals plugin: that plugin is
            // frontend-only, so builds run by the queue cron (crontab area) stored
            // "¥168,000.00" while CLI builds stored "¥168,000". Same result in both now.
            $precision = $this->configHelper->isHideZeroDecimals($storeId)
                ? 0
                : PriceCurrencyInterface::DEFAULT_PRECISION;

            $done = 0;
            if ($onProgress) {
                $onProgress($done, $total);
            }
            // Keyset pages (entity_id > last seen), not OFFSET: every page costs the
            // same however deep it is, and a product added or deleted mid-run cannot
            // shift the pages and make the build skip a row.
            $lastId = 0;
            do {
                $collection = $this->createProductCollection($storeId, $productIds, $designId);
                $collection->addFieldToFilter('entity_id', ['gt' => $lastId]);
                $collection->getSelect()
                    ->order('e.entity_id ' . Select::SQL_ASC)
                    ->limit(self::PAGE_SIZE);
                $pageCount = 0;
                foreach ($collection as $product) {
                    $pageCount++;
                    $lastId = (int)$product->getId();
                    $productsData[$product->getId()] = $this->buildProductData(
                        $product,
                        $store,
                        $storeId,
                        $currencyCode,
                        $currencySymbol,
                        $precision
                    );
                    if ($onProgress) {
                        $onProgress(++$done, $total);
                    }
                }
                // Release this page's product models before the next page loads.
                $collection->clear();
            } while ($pageCount === self::PAGE_SIZE);

        } catch (Exception $exception) {
            $this->logger->error('Error Building Products', ['exception' => $exception]);
            return $productsData;
        }

        return $productsData;
    }

    /**
     * Build the persisted row for one product
     *
     * @param Product $product
     * @param Store $store
     * @param int $storeId
     * @param string $currencyCode
     * @param string $currencySymbol
     * @param int $precision Decimals in the formatted price strings
     * @return array
     */
    private function buildProductData(
        Product $product,
        Store $store,
        int $storeId,
        string $currencyCode,
        string $currencySymbol,
        int $precision
    ): array {
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
         * IMPORTANT: Add every new row to the carpetavenue_productify_collection table.
         * -----------------------------------
         */
        return [
            // Basic info
            'id'           => $product->getId(),
            'store_id'     => $storeId,
            'sku'          => $product->getSku(),
            'url'          => $product->getProductUrl(),
            'final_status' => true,
            'is_salable'   => (int)$product->getData('is_salable'),
            'online'       => $productOnline,
            'status'       => $productStatus,

            // Images. Both ids are declared in the theme's view.xml — see
            // getProductImageUrl() for why they must never be resized here.
            'image'        => $this->getProductImageUrl($product, 'product_page_image_small'),
            'swatch_image' => $this->getProductImageUrl($product, 'productify_variant_swatch'),

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
            'price_formatted'         => $this->format($dispPrice, $store, $currencyCode, $precision),
            'final_price_formatted'   => $this->format($dispFinalPrice, $store, $currencyCode, $precision),
            'special_price_formatted' => $minimalPrice
                ? $this->format($dispMinPrice, $store, $currencyCode, $precision)
                : '',
            'minimum_price_formated'  => $baseSpecial
                ? $this->format($dispSpecial, $store, $currencyCode, $precision)
                : '',

            // Attribute labels
            Data::NAME_ATTRIBUTE_CODE => $product->getData(Data::NAME_ATTRIBUTE_CODE),
            Data::DESIGN_ID_GROUP_ATTRIBUTE_CODE => $product->getData(Data::DESIGN_ID_GROUP_ATTRIBUTE_CODE),
            Data::COLOR_ATTRIBUTE_CODE           => $product->getAttributeText(Data::COLOR_ATTRIBUTE_CODE),
            Data::SIZE_ATTRIBUTE_CODE            => $product->getAttributeText(Data::SIZE_ATTRIBUTE_CODE),
            Data::SHAPE_ATTRIBUTE_CODE           => $product->getAttributeText(Data::SHAPE_ATTRIBUTE_CODE),
            Data::SHAPE_ATTRIBUTE_KEY_CODE       => $product->getData(Data::SHAPE_ATTRIBUTE_CODE),
        ];
    }

    /**
     * Create Product Collection for Store
     *
     * @param int $storeId Store Id
     * @param array $productIds Product IDs to filter, empty for all
     * @param string|string[]|null $designId Design ID, or several design IDs
     *
     * @throws NoSuchEntityException
     */
    public function createProductCollection(
        int $storeId,
        array $productIds = [],
        string|array|null $designId = null
    ): Collection {
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
            $collection->addAttributeToFilter(
                Data::DESIGN_ID_GROUP_ATTRIBUTE_CODE,
                is_array($designId) ? ['in' => $designId] : ['eq' => $designId]
            );
        }

        // Add stock data - this adds is_in_stock, qty, etc.
        $websiteId = $this->storeManager->getWebsite(
            $this->storeManager->getStore($storeId)->getWebsiteId()
        )->getCode();
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
     * @param Store $store
     * @param string $currencyCode
     * @return float
     */
    private function convert(float $amount, Store $store, string $currencyCode): float
    {
        return $this->priceCurrency->convert($amount, $store, $currencyCode);
    }

    /**
     * Format amount as currency string
     *
     * @param float $amount
     * @param Store $store
     * @param string $currencyCode
     * @param int $precision
     * @return string
     */
    private function format(float $amount, Store $store, string $currencyCode, int $precision): string
    {
        return $this->priceCurrency->format(
            $amount,
            false,
            $precision,
            $store,
            $currencyCode
        );
    }

    /**
     * Get the product image URL for a view.xml-declared image id.
     *
     * Deliberately no ->resize()/->constrainOnly()/->keepAspectRatio() chain: those
     * override view.xml at runtime, and Magento can only regenerate a derivative
     * whose params come from a view.xml <image> entry
     * (MediaStorage\Service\ImageResize::getViewImages). An overridden size yields a
     * cache hash neither `catalog:images:resize` nor pub/get.php can ever reproduce,
     * so the moment the file is removed the URL serves the placeholder forever —
     * and the URL outlives the file, because it is persisted in
     * carpetavenue_productify_group_product_cache / carpetavenue_productify_collection.
     *
     * Add a new <image id> to the theme's view.xml instead of resizing here.
     *
     * @param Product|null $product
     * @param string $imageId A view.xml-declared image id.
     * @return false|string
     */
    public function getProductImageUrl(
        ?Product $product = null,
        string $imageId = 'product_page_image_small'
    ): false|string {
        if (!$product) {
            return false;
        }
        return $this->imageHelper->init($product, $imageId)->getUrl();
    }
}
