<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Helper;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\UrlInterface;

class SimilarTo extends AbstractHelper
{
    /**
     * @param Context $context
     * @param AttributeRepositoryInterface $attributeRepository
     * @param ProductRepositoryInterface $productRepository
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        Context                                $context,
        protected AttributeRepositoryInterface $attributeRepository,
        protected ProductRepositoryInterface   $productRepository,
        protected UrlInterface                 $urlBuilder,
    ) {
        parent::__construct($context);
    }

    /**
     * Get product by SKU
     * @param $sku
     * @return ProductInterface|null
     */
    public function getProductBySku($sku): ?ProductInterface
    {
        try {
            return $this->productRepository->get($sku);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Find the deepest category
     * @param Product $product
     * @return Category|null
     */
    public function getDeepestCategory(Product $product): ?Category
    {
        $depth = 0;
        $deepestCategory = null;

        /** @var Category $category */
        foreach ($product->getCategoryCollection() as $category) {
            if (($levels = substr_count($category->getPath(), "/")) > $depth) {
                $depth = $levels;
                $deepestCategory = $category;
            }
        }

        return $deepestCategory;
    }

    public function getSimilarToUrl($product, $useParentCategory = false, $searchPageTrigger = false, $filters = []): false|string
    {
        $queryParams = [];
        if (isset($filters['filters']) && count($filters['filters'])) {
            foreach ($filters['filters'] as $key => $value) {
                $queryParams[] = $key . "=" . $value;
            }
        }
        if (isset($filters['sort']) && count($filters['sort'])) {
            foreach ($filters['sort'] as $key => $value) {
                $queryParams[] = "sort_" . $key . "=" . $value;
            }
        }

        $queryString = implode('&', $queryParams);
        if ($searchPageTrigger) {
            return $this->urlBuilder->getUrl("catalogsearch/result/?similar={$product->getId()}&q=" . $product->getName() . "&" . $queryString);
        }

        $category = $this->getDeepestCategory($product);
        if ($useParentCategory) {
            $category = $category->getParentCategory();
        }

        if (null != $category) {
            if ($queryString != "") {
                return $this->urlBuilder->getUrl($category->getUrl() . "?similar={$product->getId()}&" . $queryString);
            } else {
                return $this->urlBuilder->getUrl($category->getUrl() . '?similarTo=' . $product->getSku());
            }
        } else {
            return false;
        }
    }
}
