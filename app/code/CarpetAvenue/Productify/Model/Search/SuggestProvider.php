<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\Model\Search;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Data behind the search suggestion dropdown: matching categories
 * ("collections"), previously-searched terms, CMS pages, and an exact
 * article-number lookup.
 */
class SuggestProvider
{
    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly PageCollectionFactory $pageCollectionFactory,
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $url,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The "Products" column is an article-number lookup, not a catalogue search:
     * carpetavenue.jp lists a rug here only when the term *is* its SKU, and
     * routes every browsing term through the category and suggested-search rows
     * instead. Verified against live — `OSG16359` returns the rug, while
     * `OSG163`, `ギャッベ` and `タブリーズ` return no products at all.
     *
     * @return array{products: ProductCollection|null, total: int}
     */
    public function getProducts(string $query, int $limit): array
    {
        try {
            $collection = $this->loadProducts($query, $limit);
            $total = $collection->getSize();

            return ['products' => $total ? $collection : null, 'total' => $total];
        } catch (\Exception $e) {
            $this->logger->warning('Productify suggest product lookup failed: ' . $e->getMessage());

            return ['products' => null, 'total' => 0];
        }
    }

    private function loadProducts(string $query, int $limit): ProductCollection
    {
        /** @var ProductCollection $collection */
        $collection = $this->productCollectionFactory->create();

        // Prices come from addPriceData()'s index join (`price` / `final_price`),
        // not from EAV — see Block\Search\Suggest::getFinalPrice().
        $collection->addAttributeToSelect(['name', 'small_image'])
            ->addPriceData()
            ->addUrlRewrite()
            ->addStoreFilter()
            ->addAttributeToFilter('sku', $query)
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter(
                'visibility',
                ['in' => [Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH]]
            )
            ->setPageSize($limit);

        return $collection;
    }

    /**
     * Categories matching the term, with the number of products in each — the
     * "Collections" rows.
     *
     * `total` is every match, not just the rows returned — it drives the
     * "See All N" chip, which reads 11 while eight rows are listed.
     *
     * @return array{items: array<int, array{label: string, url: string, count: int}>, total: int}
     */
    public function getCategories(string $query, int $limit): array
    {
        try {
            $collection = $this->categoryCollectionFactory->create();
            $collection->setStore($this->storeManager->getStore())
                ->addAttributeToSelect(['name', 'url_key', 'url_path'])
                ->addAttributeToFilter('is_active', 1)
                ->addAttributeToFilter('name', ['like' => '%' . $query . '%'])
                ->addAttributeToFilter('level', ['gt' => 1])
                ->setLoadProductCount(true)
                ->setOrder('level', 'ASC')
                ->setPageSize($limit);

            $items = [];
            foreach ($collection as $category) {
                $items[] = [
                    'label' => (string) $category->getName(),
                    'url' => (string) $category->getUrl(),
                    'count' => (int) $category->getProductCount(),
                ];
            }

            return ['items' => $items, 'total' => (int) $collection->getSize()];
        } catch (\Exception $e) {
            $this->logger->warning('Productify suggest category lookup failed: ' . $e->getMessage());

            return ['items' => [], 'total' => 0];
        }
    }

    /**
     * Terms other shoppers have searched for — the "Suggested searches" rows.
     *
     * Queried directly rather than through Search\ResourceModel\Query\Collection
     * because Plugin\Search\FilterSpamQueries gates that collection at
     * `popularity > 5` for the popular-terms page, which would leave this list
     * empty on any store that isn't already busy. Only `num_results` matters
     * here: never suggest a search that lands on an empty results page.
     *
     * @return array<int, array{label: string, url: string}>
     */
    public function getSuggestions(string $query, int $limit): array
    {
        try {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from($this->resource->getTableName('search_query'), ['query_text'])
                ->where('store_id = ?', (int) $this->storeManager->getStore()->getId())
                ->where('num_results > 0')
                ->where('query_text LIKE ?', '%' . $query . '%')
                ->where('query_text != ?', $query)
                ->order('popularity DESC')
                ->limit($limit);

            $items = [];
            foreach ($connection->fetchCol($select) as $text) {
                $items[] = [
                    'label' => (string) $text,
                    'url' => $this->url->getUrl('catalogsearch/result', ['_query' => ['q' => $text]]),
                ];
            }

            return $items;
        } catch (\Exception $e) {
            $this->logger->warning('Productify suggest term lookup failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * CMS pages matching the term.
     *
     * @return array<int, array{label: string, url: string}>
     */
    public function getPages(string $query, int $limit): array
    {
        try {
            $collection = $this->pageCollectionFactory->create();
            $collection->addFieldToFilter('is_active', 1)
                ->addFieldToFilter('title', ['like' => '%' . $query . '%'])
                ->addStoreFilter($this->storeManager->getStore()->getId())
                ->setPageSize($limit);

            $items = [];
            foreach ($collection as $page) {
                $items[] = [
                    'label' => (string) $page->getTitle(),
                    'url' => $this->url->getUrl(null, ['_direct' => $page->getIdentifier()]),
                ];
            }

            return $items;
        } catch (\Exception $e) {
            $this->logger->warning('Productify suggest CMS lookup failed: ' . $e->getMessage());

            return [];
        }
    }
}
