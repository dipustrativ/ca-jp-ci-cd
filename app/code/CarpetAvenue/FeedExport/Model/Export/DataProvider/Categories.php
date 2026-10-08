<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_FeedExport
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\FeedExport\Model\Export\DataProvider;

use CarpetAvenue\FeedExport\Model\Export\ExportContext;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator;
use Magento\Store\Model\StoreManagerInterface;

/**
 * <cats><cat> nodes: category fields + path_name ("Root Catalog > Default Category > Rugs") + product_url.
 * All categories of the store are loaded once per run instead of one repository call per product.
 */
class Categories implements DataProviderInterface
{
    private const CACHE_KEY = 'categories';

    private const CATEGORY_FIELDS = [
        'entity_id', 'parent_id', 'path', 'position', 'level', 'children_count', 'name', 'url_key', 'url_path',
        'is_active', 'include_in_menu', 'description', 'meta_title',
    ];

    public function __construct(
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductUrlPathGenerator $productUrlPathGenerator
    ) {
    }

    public function provide(Product $product, ExportContext $context): array
    {
        if (!$context->isRequired('cats')) {
            return [];
        }
        return ['cats' => $this->getCategoryNodes($product, $context)];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCategoryNodes(Product $product, ExportContext $context): array
    {
        /** @var array<int, Category> $categories */
        $categories = $context->getCache(self::CACHE_KEY, fn() => $this->loadCategories($context->getStoreId()));
        $storeId = $context->getStoreId();
        $rootPrefix = $storeId
            ? '1/' . $this->storeManager->getStore($storeId)->getRootCategoryId() . '/'
            : null;
        $withUrl = $context->isRequired('product_url');

        $nodes = [];
        foreach ($product->getCategoryIds() as $categoryId) {
            $category = $categories[(int)$categoryId] ?? null;
            if (!$category || ($rootPrefix && !str_starts_with($category->getPath() . '/', $rootPrefix))) {
                continue;
            }
            $node = [];
            foreach (self::CATEGORY_FIELDS as $field) {
                $value = $category->getData($field);
                if (is_scalar($value) && $value !== '') {
                    $context->write($node, $field, $value);
                }
            }
            $node['path_name'] = $this->getPathName($category, $categories);
            if ($withUrl) {
                $url = (string)$this->productUrlPathGenerator->getUrlPathWithSuffix($product, $storeId, $category);
                if ($context->getProfile()->isUrlRemoveStore()) {
                    $url = (string)preg_replace(['/(&|\?)___store=[^&]*$/', '/(&|\?)___store=[^&]*&/'], ['', '$1'], $url);
                }
                $node['product_url'] = $url;
            }
            $nodes[] = $node;
        }
        return $nodes;
    }

    /**
     * @param array<int, Category> $categories
     */
    private function getPathName(Category $category, array $categories): string
    {
        $names = [];
        foreach ($category->getPathIds() as $pathId) {
            $name = isset($categories[(int)$pathId]) ? (string)$categories[(int)$pathId]->getName() : '';
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return implode(' > ', $names);
    }

    /**
     * @return array<int, Category>
     */
    private function loadCategories(int $storeId): array
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect(['name', 'url_key', 'url_path', 'is_active', 'include_in_menu', 'meta_title']);
        $categories = [];
        foreach ($collection as $category) {
            $categories[(int)$category->getId()] = $category;
        }
        return $categories;
    }
}
