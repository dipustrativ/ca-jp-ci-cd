<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\LayeredNavigation\Controller;

use CarpetAvenue\Productify\LayeredNavigation\Model\Config;
use CarpetAvenue\Productify\LayeredNavigation\Model\Seo\UrlBuilder;
use CarpetAvenue\Productify\LayeredNavigation\Model\Seo\UrlParser;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;

/**
 * Runs before the core urlrewrite router (sortOrder 15 < 20): pops trailing
 * "{attributeCode}-{slug}[_{slug}...]" segments off the request path, injects
 * them as filter params, and rewrites the path back to the plain category
 * path. Returns null so the urlrewrite router resolves the category normally.
 */
class Router implements RouterInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly UrlParser $urlParser,
        private readonly UrlBuilder $urlBuilder,
        private readonly UrlFinderInterface $urlFinder,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritdoc
     */
    public function match(RequestInterface $request): ?ActionInterface
    {
        if (!$this->config->isSeoEnabled() || !$request instanceof HttpRequest) {
            return null;
        }

        // Runs on EVERY frontend request (sortOrder 15). Everything below costs a
        // config read plus a slug-map cache read, so screen on strings first.
        if (!$this->couldCarryFilterSegment($request->getPathInfo())) {
            return null;
        }

        $storeId = (int)$this->storeManager->getStore()->getId();
        $parsed = $this->urlParser->parse(
            $request->getPathInfo(),
            $storeId,
            $this->urlBuilder->getCategoryUrlSuffix($storeId)
        );
        if ($parsed === null) {
            return null;
        }

        // The remaining prefix must be a real category rewrite, otherwise the
        // segments only looked like filters — leave the request untouched.
        $rewrite = $this->urlFinder->findOneByData([
            UrlRewrite::REQUEST_PATH => $parsed['categoryPath'],
            UrlRewrite::STORE_ID => $storeId,
        ]);
        if ($rewrite === null || $rewrite->getEntityType() !== 'category') {
            return null;
        }

        $request->setPathInfo('/' . $parsed['categoryPath']);
        $request->setParams($parsed['params']);

        // Not our action to dispatch — the urlrewrite router (sortOrder 20)
        // picks up the rewritten path.
        return null;
    }

    /**
     * Can this path possibly end in a "{attributeCode}-{slug}" filter segment?
     * Deliberately loose: must never return a false negative, UrlParser does the
     * real validation. A filter segment always follows a category segment.
     *
     * @param string $pathInfo
     * @return bool
     */
    private function couldCarryFilterSegment(string $pathInfo): bool
    {
        $path = trim($pathInfo, '/');
        $lastSlash = strrpos($path, '/');
        if ($lastSlash === false) {
            return false;
        }

        return str_contains(substr($path, $lastSlash + 1), '-');
    }
}
