<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\Model\Search;

use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Framework\Api\FilterBuilderFactory;
use Magento\Framework\Api\Search\SearchCriteriaBuilderFactory;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\UrlInterface;
use Magento\Search\Api\SearchInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Data behind the search suggestion dropdown: products from OpenSearch plus
 * matching categories ("collections") and CMS pages.
 */
class SuggestProvider
{
    /**
     * Same ordering the search results page uses, so the dropdown and the full
     * result list agree on what comes first.
     */
    private const ORDERS = ['is_out_of_stock' => 'DESC', '_score' => 'DESC'];

    public function __construct(
        private readonly SearchInterface $search,
        private readonly SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        private readonly FilterBuilderFactory $filterBuilderFactory,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly PageCollectionFactory $pageCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $url,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{products: ProductCollection|null, total: int}
     */
    public function getProducts(string $query, int $limit): array
    {
        $ids = [];
        $total = 0;

        try {
            // Fresh builder instances: the framework's SearchCriteriaBuilder is a
            // shared singleton that the fulltext collection also grabs, so reusing
            // it leaks filters between searches in the same request (see
            // LayeredNavigation\Model\Search\FacetProvider::isolateCriteriaBuilders).
            $criteriaBuilder = $this->searchCriteriaBuilderFactory->create();
            $filterBuilder = $this->filterBuilderFactory->create();

            $criteriaBuilder->addFilter(
                $filterBuilder->setField('search_term')->setValue($query)->create()
            );
            $criteriaBuilder->addFilter(
                $filterBuilder->setField('visibility')
                    ->setValue([Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH])
                    ->create()
            );

            $criteria = $criteriaBuilder->create();
            $criteria->setRequestName('quick_search_container');
            $criteria->setCurrentPage(0);
            $criteria->setPageSize($limit);
            $criteria->setSortOrders(self::ORDERS);

            $result = $this->search->search($criteria);
            $total = (int) $result->getTotalCount();

            foreach ($result->getItems() as $item) {
                $ids[] = (int) $item->getId();
            }
        } catch (\Exception $e) {
            $this->logger->warning('Productify suggest search failed: ' . $e->getMessage());

            return ['products' => null, 'total' => 0];
        }

        if (!$ids) {
            return ['products' => null, 'total' => $total];
        }

        return ['products' => $this->loadProducts($ids), 'total' => $total];
    }

    /**
     * Hydrate the search hits in one collection, keeping relevance order.
     */
    private function loadProducts(array $ids): ProductCollection
    {
        /** @var ProductCollection $collection */
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'small_image', 'width', 'length', 'shape'])
            ->addPriceData()
            ->addUrlRewrite()
            ->addStoreFilter();

        $collection->getSelect()
            ->where('e.entity_id IN (?)', $ids)
            ->reset(Select::ORDER)
            ->order(new Expression('FIELD(e.entity_id, ' . implode(',', $ids) . ')'));

        return $collection;
    }

    /**
     * Categories matching the term — the "collections" column.
     *
     * @return array<int, array{label: string, url: string}>
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
                ->setOrder('level', 'ASC')
                ->setPageSize($limit);

            $items = [];
            foreach ($collection as $category) {
                $items[] = ['label' => (string) $category->getName(), 'url' => (string) $category->getUrl()];
            }

            return $items;
        } catch (\Exception $e) {
            $this->logger->warning('Productify suggest category lookup failed: ' . $e->getMessage());

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
