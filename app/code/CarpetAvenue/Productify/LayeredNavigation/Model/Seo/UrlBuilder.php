<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\LayeredNavigation\Model\Seo;

use CarpetAvenue\Productify\LayeredNavigation\Model\Config;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Layer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds SEO filter URLs: category URL + one path segment per filtered
 * attribute ("color-red_blue"), everything else (price, page, sort) as query
 * params. Segments are sorted by attribute code so every filter combination
 * has exactly one canonical URL.
 */
class UrlBuilder
{
    /**
     * build() runs once per rendered filter option — 100+ times per category
     * page — so the config read it opens with is memoised per store.
     *
     * @var array<int, string>
     */
    private array $suffixes = [];

    public function __construct(
        private readonly SlugProvider $slugProvider,
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * SEO URLs apply on category pages only — search results keep query params.
     */
    public function isApplicable(Layer $layer): bool
    {
        return $this->config->isSeoEnabled() && !$layer instanceof Layer\Search;
    }

    /**
     * @param array<string, string> $attributeParams attributeCode => "5,6"
     * @param array<string, mixed> $queryParams
     */
    public function build(CategoryInterface $category, array $attributeParams, array $queryParams = []): ?string
    {
        $storeId = (int)$this->storeManager->getStore()->getId();
        $suffix = $this->getCategoryUrlSuffix($storeId);

        $url = rtrim((string)$category->getUrl(), '/');
        if ($suffix !== '' && str_ends_with($url, $suffix)) {
            $url = substr($url, 0, -strlen($suffix));
        }

        ksort($attributeParams);
        foreach ($attributeParams as $code => $values) {
            $slugs = [];
            foreach (explode(',', (string)$values) as $optionId) {
                if ($optionId === '') {
                    continue;
                }
                $slug = $this->slugProvider->getSlugByOptionId($storeId, (string)$code, $optionId);
                if ($slug === null) {
                    // Unknown option — cannot express this state as an SEO path.
                    return null;
                }
                $slugs[] = $slug;
            }
            if ($slugs !== []) {
                sort($slugs);
                $url .= '/' . $code . '-' . implode($this->config->getMultiValueSeparator(), $slugs);
            }
        }

        $url .= $suffix;

        $queryParams = array_filter($queryParams, static fn ($value) => $value !== null && $value !== '');
        if ($queryParams !== []) {
            $url .= '?' . http_build_query($queryParams);
        }

        return $url;
    }

    public function getCategoryUrlSuffix(int $storeId): string
    {
        return $this->suffixes[$storeId] ??= (string)$this->scopeConfig->getValue(
            'catalog/seo/category_url_suffix',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
