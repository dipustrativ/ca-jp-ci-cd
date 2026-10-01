<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\Controller\Search;

use CarpetAvenue\Productify\Block\Search\Suggest as SuggestBlock;
use CarpetAvenue\Productify\Model\Search\SuggestConfig;
use CarpetAvenue\Productify\Model\Search\SuggestProvider;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\View\LayoutFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Search suggestion dropdown: /productify/search/suggest?q=...
 *
 * Returns a rendered HTML fragment (not JSON) so escaping, translation and the
 * store's price formatting all come from the template layer — the JS side is a
 * single innerHTML assignment. Same posture as Controller\SimilarTo\Ajax.
 */
class Suggest implements HttpGetActionInterface
{
    private const CACHE_PREFIX = 'productify_suggest_';

    public function __construct(
        private readonly \Magento\Framework\App\RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly LayoutFactory $layoutFactory,
        private readonly SuggestProvider $provider,
        private readonly SuggestConfig $config,
        private readonly CacheInterface $cache,
        private readonly CustomerSession $customerSession,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function execute(): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/html; charset=UTF-8', true);
        // Group- and tax-dependent markup: never let a shared cache hold it.
        // The Redis entry below is what makes this fast, and its key carries the
        // customer group (the bug documented in Controller\SimilarTo\Ajax).
        $result->setHeader('Cache-Control', 'no-store', true);

        $store = $this->storeManager->getStore();
        $storeId = (int) $store->getId();

        if (!$this->config->isEnabled($storeId)) {
            return $result->setContents('');
        }

        $query = trim((string) $this->request->getParam('q', ''));
        if (mb_strlen($query) < $this->config->getMinChars($storeId)) {
            return $result->setContents('');
        }
        // Cap the term so a pasted essay can't reach OpenSearch.
        $query = mb_substr($query, 0, 150);

        $cacheKey = self::CACHE_PREFIX . implode('_', [
            $storeId,
            (int) $this->customerSession->getCustomerGroupId(),
            $store->getCurrentCurrencyCode(),
            hash('sha256', mb_strtolower($query)),
        ]);

        $ttl = $this->config->getCacheTtl($storeId);
        if ($ttl > 0) {
            $cached = $this->cache->load($cacheKey);
            if ($cached !== false && $cached !== null) {
                return $result->setContents($cached)->setHeader('X-Productify-Suggest', 'HIT', true);
            }
        }

        $html = $this->render($query, $storeId);

        if ($ttl > 0) {
            $this->cache->save(
                $html,
                $cacheKey,
                [Product::CACHE_TAG, Category::CACHE_TAG],
                $ttl
            );
        }

        return $result->setContents($html)->setHeader('X-Productify-Suggest', 'MISS', true);
    }

    private function render(string $query, int $storeId): string
    {
        $products = $this->provider->getProducts($query, $this->config->getProductLimit($storeId));
        $categories = $this->provider->getCategories($query, SuggestConfig::SIDE_LIMIT);

        /** @var SuggestBlock $block */
        $block = $this->layoutFactory->create()->createBlock(SuggestBlock::class);
        $block->setTemplate('CarpetAvenue_Productify::search/suggest.phtml')
            ->setData('query', $query)
            ->setData('products', $products['products'])
            ->setData('total', $products['total'])
            ->setData('categories', $categories['items'])
            ->setData('categories_total', $categories['total'])
            ->setData('suggestions', $this->provider->getSuggestions($query, SuggestConfig::SIDE_LIMIT));

        return $block->toHtml();
    }
}
