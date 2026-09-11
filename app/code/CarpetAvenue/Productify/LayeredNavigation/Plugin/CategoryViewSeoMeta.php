<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\LayeredNavigation\Plugin;

use CarpetAvenue\Productify\LayeredNavigation\Model\Config;
use CarpetAvenue\Productify\LayeredNavigation\Model\Search\FacetProvider;
use CarpetAvenue\Productify\LayeredNavigation\Model\Seo\SlugProvider;
use CarpetAvenue\Productify\LayeredNavigation\Model\Seo\UrlBuilder;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
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
    private const CATEGORY_PARAM = 'cat';

    public function __construct(
        private readonly Config $config,
        private readonly SlugProvider $slugProvider,
        private readonly UrlBuilder $urlBuilder,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly FacetProvider $facetProvider,
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

        // Picking a category is navigation, not filtering, so it has to work whether
        // or not SEO filter paths are switched on. Deliberately outside the flag
        // below: turning SEO URLs off must not silently revert this.
        $redirect = $this->redirectCategoryPick($pathCodes, $storeId);
        if ($redirect !== null) {
            return $redirect;
        }

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
     * Sends a category pick to that category's own page, carrying the rest of the
     * filter state. Runs in both URL modes, emitting SEO paths when those are on
     * and plain query params when they are off.
     *
     * @param string[] $pathCodes
     */
    private function redirectCategoryPick(array $pathCodes, int $storeId): ?Redirect
    {
        $query = $this->request->getQuery()->toArray();
        $catParam = $query[self::CATEGORY_PARAM] ?? null;
        if ($catParam === null || $catParam === '') {
            return null;
        }

        $current = $this->loadCategory((int)$this->request->getParam('id'), $storeId);
        if ($current === null) {
            return null;
        }

        $target = $this->resolvePickedCategory($catParam, $current, $storeId);
        if ($target === null) {
            return null;
        }

        // Filters already canonicalised into SEO path segments reach us as
        // router-injected params rather than query params, so read the whole filter
        // state rather than just the query string.
        $attributeParams = $this->readAppliedFilters($pathCodes);
        // A colour with matches in the parent may have none in the child, and
        // carrying it over would land the shopper on an empty page.
        $attributeParams = $this->facetProvider->keepMatchingValues($target, $attributeParams);

        $restQuery = array_diff_key($query, array_flip($pathCodes));
        unset($restQuery[self::CATEGORY_PARAM]);

        $url = $this->buildTargetUrl($target, $attributeParams, $restQuery);
        if ($url === null) {
            return null;
        }

        return $this->redirectFactory->create()
            ->setUrl($url)
            ->setHttpResponseCode(301);
    }

    /**
     * @param array<string, string> $attributeParams
     * @param array<string, mixed> $restQuery
     */
    private function buildTargetUrl(
        CategoryInterface $target,
        array $attributeParams,
        array $restQuery
    ): ?string {
        if ($this->config->isSeoEnabled()) {
            return $this->urlBuilder->build($target, $attributeParams, $restQuery);
        }

        // SEO paths off: every filter stays a query param, matching the rest of the
        // links the layer builds in this mode.
        $params = array_filter(
            array_merge($restQuery, $attributeParams),
            static fn ($value) => $value !== null && $value !== ''
        );
        $url = (string)$target->getUrl();

        return $params === [] ? $url : $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
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

        $category = $this->loadCategory((int)$this->request->getParam('id'), $storeId);
        if ($category === null) {
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
     * Every path-attribute filter currently in force, however it was expressed.
     *
     * getParam() covers both the router-injected SEO segments and plain query
     * values; getQuery() alone would see only the latter.
     *
     * @param string[] $pathCodes
     * @return array<string, string>
     */
    private function readAppliedFilters(array $pathCodes): array
    {
        $applied = [];

        foreach ($pathCodes as $code) {
            $value = $this->request->getParam($code);
            if (is_string($value) && $value !== '') {
                $applied[$code] = $value;
            }
        }

        return $applied;
    }

    /**
     * The category the shopper picked in the filter, or null to stay put.
     *
     * Only ever moves *down* the tree. The filter lists children, so an id naming
     * anything else came from a hand-edited URL and must not bounce the shopper
     * off to an unrelated branch.
     */
    private function resolvePickedCategory(
        mixed $catParam,
        CategoryInterface $current,
        int $storeId
    ): ?CategoryInterface {
        if (!is_scalar($catParam)) {
            return null;
        }

        $pickedId = (int)$catParam;
        if ($pickedId <= 0 || $pickedId === (int)$current->getId()) {
            return null;
        }

        $picked = $this->loadCategory($pickedId, $storeId);
        if ($picked === null || !$picked->getIsActive()) {
            return null;
        }

        $ancestors = explode('/', (string)$picked->getPath());

        return in_array((string)$current->getId(), $ancestors, true) ? $picked : null;
    }

    private function loadCategory(int $categoryId, int $storeId): ?CategoryInterface
    {
        try {
            return $this->categoryRepository->get($categoryId, $storeId);
        } catch (NoSuchEntityException $e) {
            return null;
        }
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
