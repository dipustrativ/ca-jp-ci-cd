<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\LayeredNavigation\Model\Search;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Category\CollectionFilter as CategoryCollectionFilter;
use Magento\Catalog\Model\Layer\Filter\AbstractFilter;
use Magento\Catalog\Model\Layer\Search\CollectionFilter as SearchCollectionFilter;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogSearch\Model\Layer\Filter\Decimal as DecimalFilter;
use Magento\CatalogSearch\Model\Layer\Filter\Price as PriceFilter;
use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection as FulltextCollection;
use Magento\Framework\Api\FilterBuilderFactory;
use Magento\Framework\Api\Search\SearchCriteriaBuilderFactory;
use Psr\Log\LoggerInterface;

/**
 * Computes facet counts for one attribute while excluding that attribute's own
 * applied filter, so sibling options of a multiselect filter keep correct counts.
 *
 * Builds a fresh fulltext collection mirroring the current layer (category or
 * search) and re-applies every filter from the layer state except the excluded
 * attribute — one extra search query per *applied* attribute only.
 */
class FacetProvider
{
    public function __construct(
        private readonly CollectionFactory $categoryCollectionFactory,
        private readonly CollectionFactory $searchCollectionFactory,
        private readonly CategoryCollectionFilter $categoryCollectionFilter,
        private readonly SearchCollectionFilter $searchCollectionFilter,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        private readonly FilterBuilderFactory $filterBuilderFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<int|string, array{value?: mixed, count: int}>
     */
    public function getFacetedData(Layer $layer, string $excludeAttributeCode): array
    {
        $collection = $this->createStatelessCollection($layer, $excludeAttributeCode);
        if ($collection === null) {
            return [];
        }

        try {
            return $collection->getFacetedData($excludeAttributeCode);
        } catch (\Exception $e) {
            $this->logger->warning(
                sprintf(
                    'LayeredNavigation facet query failed for "%s": %s',
                    $excludeAttributeCode,
                    $e->getMessage()
                )
            );

            return [];
        }
    }

    /**
     * Keeps only the option values that still have products in the given category.
     *
     * Used when a category filter moves the shopper to a child category: a colour
     * that had matches in the parent may have none in the child, and carrying it
     * over would land them on an empty page. Deliberately independent of the layer
     * state, because the SEO redirect runs before the layer is built.
     *
     * One search query per attribute code, and only on the redirect itself, not on
     * every page render. Returns the input unchanged if the query fails, so a
     * search outage degrades to carrying everything rather than to an error.
     *
     * @param array<string, string> $attributeParams attributeCode => "5,6"
     * @return array<string, string>
     */
    public function keepMatchingValues(CategoryInterface $category, array $attributeParams): array
    {
        $kept = [];

        foreach ($attributeParams as $code => $values) {
            $requested = array_values(array_filter(explode(',', (string)$values), 'strlen'));
            if ($requested === []) {
                continue;
            }

            $counts = $this->getCategoryFacets($category, (string)$code);
            if ($counts === null) {
                //  Could not ask — keep what we were given.
                $kept[$code] = (string)$values;
                continue;
            }

            $surviving = array_values(array_filter(
                $requested,
                static fn ($optionId) => (int)($counts[$optionId]['count'] ?? 0) > 0
            ));

            if ($surviving !== []) {
                $kept[$code] = implode(',', $surviving);
            }
        }

        return $kept;
    }

    /**
     * Facet counts for one attribute across a category, with no other filter applied.
     *
     * @return array<int|string, array{value?: mixed, count: int}>|null null on failure
     */
    private function getCategoryFacets(CategoryInterface $category, string $attributeCode): ?array
    {
        try {
            /** @var FulltextCollection $collection */
            $collection = $this->categoryCollectionFactory->create();
            $this->isolateCriteriaBuilders($collection);
            $collection->addCategoryFilter($category);

            return $collection->getFacetedData($attributeCode);
        } catch (\Exception $e) {
            $this->logger->warning(
                sprintf(
                    'LayeredNavigation category facet check failed for "%s" in category %s: %s',
                    $attributeCode,
                    (string)$category->getId(),
                    $e->getMessage()
                )
            );

            return null;
        }
    }

    /**
     * Collection mirroring the layer with every state filter applied except
     * the given attribute — e.g. price bounds for the slider. Null on failure.
     */
    public function createStatelessCollection(Layer $layer, string $excludeAttributeCode): ?FulltextCollection
    {
        try {
            $collection = $this->createCollection($layer);
            $this->applyStateFilters($collection, $layer, $excludeAttributeCode);

            return $collection;
        } catch (\Exception $e) {
            $this->logger->warning(
                sprintf(
                    'LayeredNavigation collection build failed excluding "%s": %s',
                    $excludeAttributeCode,
                    $e->getMessage()
                )
            );

            return null;
        }
    }

    /**
     * Signature of the state createStatelessCollection() would build with —
     * anything cached off that collection must be keyed on this.
     */
    public function getStateSignature(Layer $layer, string $excludeAttributeCode): string
    {
        $parts = [];

        foreach ($layer->getState()->getFilters() as $item) {
            $filter = $item->getFilter();
            $requestVar = (string)$filter->getRequestVar();

            if ($requestVar !== 'cat') {
                if (!$filter instanceof AbstractFilter || !$filter->hasAttributeModel()) {
                    continue;
                }
                if ($filter->getAttributeModel()->getAttributeCode() === $excludeAttributeCode) {
                    continue;
                }
            }

            $value = $item->getValue();
            $parts[] = $requestVar . ':' . (is_array($value) ? implode('-', $value) : (string)$value);
        }

        if ($parts === []) {
            return 'none';
        }

        sort($parts);

        return hash('sha256', implode('|', $parts));
    }

    private function createCollection(Layer $layer): FulltextCollection
    {
        if ($layer instanceof Layer\Search) {
            /** @var FulltextCollection $collection */
            $collection = $this->searchCollectionFactory->create();
            $this->isolateCriteriaBuilders($collection);
            // The catalogsearch "searchQuery" plugin on this filter adds the search term.
            $this->searchCollectionFilter->filter($collection, $layer->getCurrentCategory());

            return $collection;
        }

        /** @var FulltextCollection $collection */
        $collection = $this->categoryCollectionFactory->create();
        $this->isolateCriteriaBuilders($collection);
        $collection->addCategoryFilter($layer->getCurrentCategory());
        $this->categoryCollectionFilter->filter($collection, $layer->getCurrentCategory());

        return $collection;
    }

    /**
     * The fulltext collection falls back to a SHARED SearchCriteriaBuilder
     * singleton (ObjectManager::getInstance()->get() in its constructor), so
     * filters leak between the layer's main collection and this one in both
     * directions. Give our collection private builder instances.
     */
    private function isolateCriteriaBuilders(FulltextCollection $collection): void
    {
        $collection->setSearchCriteriaBuilder($this->searchCriteriaBuilderFactory->create());
        $collection->setFilterBuilder($this->filterBuilderFactory->create());
    }

    private function applyStateFilters(
        FulltextCollection $collection,
        Layer $layer,
        string $excludeAttributeCode
    ): void {
        $attributeValues = [];

        foreach ($layer->getState()->getFilters() as $item) {
            $filter = $item->getFilter();

            if ($filter->getRequestVar() === 'cat') {
                $category = $this->categoryRepository->get(
                    (int)$item->getValue(),
                    (int)$layer->getCurrentStore()->getId()
                );
                $collection->addCategoryFilter($category);
                continue;
            }

            if (!$filter instanceof AbstractFilter || !$filter->hasAttributeModel()) {
                continue;
            }

            $code = $filter->getAttributeModel()->getAttributeCode();
            if ($code === $excludeAttributeCode) {
                continue;
            }

            if ($filter instanceof PriceFilter || $filter instanceof DecimalFilter) {
                // Stock price state items carry [from, to] arrays, decimal ones "from-to" strings.
                $value = $item->getValue();
                $range = is_array($value)
                    ? $this->buildRange((string)($value[0] ?? ''), (string)($value[1] ?? ''))
                    : $this->parseRange((string)$value);
                if ($range !== null) {
                    $collection->addFieldToFilter($code, $range);
                }
                continue;
            }

            $attributeValues[$code][] = (string)$item->getValue();
        }

        foreach ($attributeValues as $code => $values) {
            $collection->addFieldToFilter($code, count($values) > 1 ? $values : $values[0]);
        }
    }

    /**
     * @return array{from?: string, to?: string}|null
     */
    private function parseRange(string $value): ?array
    {
        if (!str_contains($value, '-')) {
            return null;
        }

        [$from, $to] = array_pad(explode('-', $value, 2), 2, '');

        return $this->buildRange($from, $to);
    }

    /**
     * @return array{from?: string, to?: string}|null
     */
    private function buildRange(string $from, string $to): ?array
    {
        $range = [];
        if ($from !== '' && is_numeric($from)) {
            $range['from'] = $from;
        }
        if ($to !== '' && is_numeric($to)) {
            $range['to'] = $to;
        }

        return $range !== [] ? $range : null;
    }
}
