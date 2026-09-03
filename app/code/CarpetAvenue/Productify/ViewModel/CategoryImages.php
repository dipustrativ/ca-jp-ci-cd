<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\ViewModel;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Exposes a category thumbnail image URL to the layered-navigation filter
 * template, so the "cat" filter can show a picture next to each category
 * (mirrors the reference Evalent/carpet look).
 *
 * The image comes from the category entity's own `thumbnail` attribute — NOT
 * from attribute-option swatches. That keeps it collision-free: category IDs
 * and attribute-option IDs share a numeric space, so the swatch-based
 * Carpient_Filter\OptionImages must never be used for categories.
 *
 * Thumbnail values in this store are inconsistent (bare filename, root-relative
 * "/media/..." path, or absolute URL), so URL building normalises all three.
 */
class CategoryImages implements ArgumentInterface
{
    /**
     * @var array<int, string|null> categoryId => resolved URL (memo, per request)
     */
    private array $cache = [];

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Absolute image URL for a category, or null when it has no thumbnail.
     *
     * @param int $categoryId
     * @return string|null
     */
    public function getUrl(int $categoryId): ?string
    {
        if ($categoryId <= 0) {
            return null;
        }

        if (array_key_exists($categoryId, $this->cache)) {
            return $this->cache[$categoryId];
        }

        return $this->cache[$categoryId] = $this->resolve($categoryId);
    }

    /**
     * @param int $categoryId
     * @return string|null
     */
    private function resolve(int $categoryId): ?string
    {
        try {
            $category = $this->categoryRepository->get($categoryId);
        } catch (NoSuchEntityException $e) {
            return null;
        }

        $thumbnail = (string) ($category->getThumbnail() ?? '');
        if ($thumbnail === '') {
            return null;
        }

        // Already an absolute URL.
        if (preg_match('#^https?://#i', $thumbnail)) {
            return $thumbnail;
        }

        // Root-relative path already containing the media segment (e.g. "/media/catalog/category/x.webp").
        if (str_starts_with($thumbnail, '/')) {
            $base = rtrim($this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/');
            return $base . $thumbnail;
        }

        $mediaBase = rtrim($this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/');

        // Relative path that carries its own folders (e.g. "catalog/category/x.jpg").
        if (str_contains($thumbnail, '/')) {
            return $mediaBase . '/' . ltrim($thumbnail, '/');
        }

        // Bare filename — lives under the standard category media folder.
        return $mediaBase . '/catalog/category/' . $thumbnail;
    }
}
