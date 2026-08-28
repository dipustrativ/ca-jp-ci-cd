<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\LayeredNavigation\Plugin;

use CarpetAvenue\Productify\LayeredNavigation\Model\Config;
use CarpetAvenue\Productify\LayeredNavigation\Model\Seo\SlugProvider;
use CarpetAvenue\Productify\LayeredNavigation\Model\Seo\UrlBuilder;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Controller\Category\View as CategoryView;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\Page;
use Magento\Store\Model\StoreManagerInterface;

/**
 * SEO handling for filtered category pages:
 *  - 301 from legacy ?attr=id URLs to the canonical SEO path
 *  - NOINDEX,FOLLOW + canonical tag on filtered pages
 */
class CategoryViewSeoMeta
{
    public function __construct(
        private readonly Config $config,
        private readonly SlugProvider $slugProvider,
        private readonly UrlBuilder $urlBuilder,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly RedirectFactory $redirectFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly HttpRequest $request,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @param callable(): mixed $proceed
     */
    public function aroundExecute(CategoryView $subject, callable $proceed): mixed
    {
        if (!$this->config->isEnabled()) {
            return $proceed();
        }

        $storeId = (int)$this->storeManager->getStore()->getId();
        $pathCodes = $this->slugProvider->getFilterableCodes($storeId);

        if ($this->config->isSeoEnabled()) {
            $redirect = $this->redirectLegacyFilterUrl($pathCodes, $storeId);
            if ($redirect !== null) {
                return $redirect;
            }
        }

        $result = $proceed();

        if ($result instanceof Page) {
            $this->applyMeta($result, $pathCodes, $storeId);
        }

        return $result;
    }

    /**
     * ?color=5 style URLs get a 301 to the slug path so crawlers and caches
     * see exactly one URL per filter state. Params injected by our router are
     * not part of the query string, so SEO-path requests pass through.
     *
     * @param string[] $pathCodes
     */
    private function redirectLegacyFilterUrl(array $pathCodes, int $storeId): ?Redirect
    {
        $query = $this->request->getQuery()->toArray();
        $attributeParams = array_intersect_key($query, array_flip($pathCodes));
        if ($attributeParams === []) {
            return null;
        }

        try {
            $category = $this->categoryRepository->get(
                (int)$this->request->getParam('id'),
                $storeId
            );
        } catch (NoSuchEntityException $e) {
            return null;
        }

        $url = $this->urlBuilder->build(
            $category,
            array_map('strval', $attributeParams),
            array_diff_key($query, $attributeParams)
        );
        if ($url === null) {
            return null;
        }

        return $this->redirectFactory->create()
            ->setUrl($url)
            ->setHttpResponseCode(301);
    }

    /**
     * @param string[] $pathCodes
     */
    private function applyMeta(Page $page, array $pathCodes, int $storeId): void
    {
        $appliedCodes = [];
        foreach ($pathCodes as $code) {
            $param = $this->request->getParam($code);
            if (is_string($param) && $param !== '') {
                $appliedCodes[$code] = $param;
            }
        }
        $hasRangeFilter = is_string($this->request->getParam('price'));

        if ($appliedCodes === [] && !$hasRangeFilter) {
            return;
        }

        if (!$this->isIndexableFilterState($appliedCodes, $hasRangeFilter)) {
            $page->getConfig()->setRobots('NOINDEX,FOLLOW');
        }

        try {
            $category = $this->categoryRepository->get(
                (int)$this->request->getParam('id'),
                $storeId
            );
        } catch (NoSuchEntityException $e) {
            return;
        }

        // Core adds its own canonical (already the bare category URL) when
        // catalog/seo/category_canonical_tag is on — don't emit a second one.
        $coreCanonical = $this->scopeConfig->isSetFlag(
            'catalog/seo/category_canonical_tag',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        if (!$coreCanonical) {
            $page->getConfig()->addRemotePageAsset(
                (string)$category->getUrl(),
                'canonical',
                ['attributes' => ['rel' => 'canonical']]
            );
        }
    }

    /**
     * A single attribute with a single value may stay indexable when the
     * admin allows it; everything else is noindex.
     *
     * @param array<string, string> $appliedCodes
     */
    private function isIndexableFilterState(array $appliedCodes, bool $hasRangeFilter): bool
    {
        return $this->config->isSeoEnabled()
            && $this->config->isIndexSingleFilterAllowed()
            && !$hasRangeFilter
            && count($appliedCodes) === 1
            && !str_contains((string)reset($appliedCodes), ',');
    }
}
