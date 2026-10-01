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
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Data behind the search suggestion dropdown: matching categories
 * ("collections"), previously-searched terms, and a single-product lookup.
 */
class SuggestProvider
{
    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $url,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The "Products" column only lists a rug when the term pins down exactly one:
     * its SKU, or a name fragment no other rug shares. Any broader term (a design
     * name such as `タブリーズ50ラジ`, shared by hundreds of rugs) lists nothing
     * here and is served by the category and suggested-search rows instead.
     * Same rule as live's Evalent\SearchSuiteAutocomplete\Model\Search\Product,
     * which matches `name LIKE %q% OR sku = q` and drops the list when size > 1.
     *
     * @return array{products: ProductCollection|null, total: int}
     */
    public function getProducts(string $query, int $limit): array
    {
        try {
            $collection = $this->loadProducts($query, $limit);
            $total = $collection->getSize();

            return ['products' => $total === 1 ? $collection : null, 'total' => $total];
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
            ->addAttributeToFilter([
                ['attribute' => 'name', 'like' => '%' . $query . '%'],
                ['attribute' => 'sku', 'eq' => $query],
            ])
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
                // Merchandisers tag categories with extra search words in
                // meta_keywords, as live's Evalent SearchSuiteAutocomplete reads them.
                // Left join: the default inner join drops every category that
                // has no meta_keywords row at all.
                ->addAttributeToFilter([
                    ['attribute' => 'name', 'like' => '%' . $query . '%'],
                    ['attribute' => 'meta_keywords', 'like' => '%' . $query . '%'],
                ], null, 'left')
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
     * Prefix match (`q%`), as live's stock Query\\Collection::setQueryFilter(),
     * with the typed term itself first when it has been searched before.
     *
     * @return array<int, array{label: string, url: string}>
     */
    public function getSuggestions(string $query, int $limit): array
    {
        try {
            $connection = $this->resource->getConnection();
            // An article number is answered by the Products row, never re-offered
            // as a "similar search" — live never lists one (its popularity > 5
            // gate keeps one-off SKU lookups out).
            $skuMatch = $connection->select()
                ->from(['p' => $this->resource->getTableName('catalog_product_entity')], [new Expression('1')])
                ->where('p.sku = sq.query_text');
            $select = $connection->select()
                ->from(['sq' => $this->resource->getTableName('search_query')], ['query_text'])
                ->where('sq.store_id = ?', (int) $this->storeManager->getStore()->getId())
                ->where('sq.num_results > 0')
                ->where('sq.query_text LIKE ?', $query . '%')
                ->where('NOT EXISTS (?)', $skuMatch)
                // The typed term itself leads, as in stock Autocomplete\DataProvider::getItems().
                ->order(new Expression($connection->quoteInto('sq.query_text = ? DESC', $query)))
                ->order('sq.popularity DESC')
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
}
