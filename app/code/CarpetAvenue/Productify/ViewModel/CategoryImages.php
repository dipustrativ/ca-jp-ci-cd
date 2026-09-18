<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\ViewModel;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Resolves category image URLs for storefront templates: the thumbnail next to
 * each "cat" layered-navigation filter, and the banner on the category view
 * (`image` / `mobile_image`).
 *
 * The image comes from the category entity's own attributes — NOT from
 * attribute-option swatches. That keeps it collision-free: category IDs and
 * attribute-option IDs share a numeric space, so the swatch-based
 * Carpient_Filter\OptionImages must never be used for categories.
 *
 * Image values in this store are inconsistent (bare filename, root-relative
 * "/media/..." path, or absolute URL), so URL building normalises all three.
 *
 * A value can also point at a file that is no longer on disk (deleted from
 * pub/media while the attribute kept its value). Every resolved URL that lives
 * under this store's media base is therefore checked against the media
 * directory, and a missing file resolves to null — callers render nothing
 * rather than a broken image. URLs outside the media base (external/CDN) can't
 * be checked and are returned as-is.
 */
class CategoryImages implements ArgumentInterface
{
    /**
     * @var array<string, string|null> "<categoryId>:<attributeCode>" => resolved URL (memo, per request)
     */
    private array $cache = [];

    /**
     * @var array<string, bool> media-relative path => exists (memo, per request)
     */
    private array $existsCache = [];

    private ?ReadInterface $mediaDirectory = null;

    /**
     * @param CategoryRepositoryInterface $categoryRepository
     * @param StoreManagerInterface $storeManager
     * @param Filesystem $filesystem
     */
    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * Absolute image URL for a category id, or null when it has no usable image.
     *
     * @param int $categoryId
     * @param string $attributeCode
     * @return string|null
     */
    public function getUrl(int $categoryId, string $attributeCode = 'thumbnail'): ?string
    {
        if ($categoryId <= 0) {
            return null;
        }

        $key = $categoryId . ':' . $attributeCode;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        try {
            $category = $this->categoryRepository->get($categoryId);
        } catch (NoSuchEntityException $e) {
            return $this->cache[$key] = null;
        }

        return $this->cache[$key] = $this->getCategoryUrl($category, $attributeCode);
    }

    /**
     * Absolute image URL for an already-loaded category, or null when the
     * attribute is empty or the file it names is missing from pub/media.
     *
     * @param CategoryInterface|null $category
     * @param string $attributeCode
     * @return string|null
     */
    public function getCategoryUrl(?CategoryInterface $category, string $attributeCode = 'image'): ?string
    {
        if ($category === null) {
            return null;
        }

        $value = $category->getData($attributeCode);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $url = $this->toUrl(trim($value));

        return $this->isUsable($url) ? $url : null;
    }

    /**
     * Normalise a stored attribute value to an absolute URL.
     *
     * @param string $value
     * @return string
     */
    private function toUrl(string $value): string
    {
        // Already an absolute URL.
        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        // Root-relative path already containing the media segment (e.g. "/media/catalog/category/x.webp").
        if (str_starts_with($value, '/')) {
            $base = rtrim($this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/');
            return $base . $value;
        }

        $mediaBase = rtrim($this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/');

        // Relative path that carries its own folders (e.g. "catalog/category/x.jpg").
        if (str_contains($value, '/')) {
            return $mediaBase . '/' . ltrim($value, '/');
        }

        // Bare filename — lives under the standard category media folder.
        return $mediaBase . '/catalog/category/' . $value;
    }

    /**
     * Whether the URL can actually be served: files under this store's media
     * base must exist on disk; anything else is taken on trust.
     *
     * @param string $url
     * @return bool
     */
    private function isUsable(string $url): bool
    {
        $mediaBase = rtrim($this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/') . '/';
        if (!str_starts_with($url, $mediaBase)) {
            return true;
        }

        $relativePath = rawurldecode(parse_url(substr($url, strlen($mediaBase)), PHP_URL_PATH) ?: '');
        if ($relativePath === '') {
            return false;
        }

        if (array_key_exists($relativePath, $this->existsCache)) {
            return $this->existsCache[$relativePath];
        }

        try {
            $exists = $this->getMediaDirectory()->isExist($relativePath);
        } catch (FileSystemException $e) {
            $exists = false;
        }

        return $this->existsCache[$relativePath] = $exists;
    }

    /**
     * Lazily opened read handle on pub/media.
     *
     * @return ReadInterface
     */
    private function getMediaDirectory(): ReadInterface
    {
        if ($this->mediaDirectory === null) {
            $this->mediaDirectory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        }

        return $this->mediaDirectory;
    }
}
