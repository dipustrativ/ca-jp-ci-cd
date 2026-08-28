<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\LayeredNavigation\Plugin;

use CarpetAvenue\Productify\LayeredNavigation\Model\Seo\SlugProvider;
use CarpetAvenue\Productify\LayeredNavigation\Model\Seo\UrlBuilder;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\LayeredNavigation\Block\Navigation\State;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Under SEO URLs, the stock "Clear All" link only strips query params and
 * would keep the filter path segments — rebuild it from the bare category URL.
 */
class StateClearUrl
{
    public function __construct(
        private readonly UrlBuilder $urlBuilder,
        private readonly SlugProvider $slugProvider,
        private readonly LayerResolver $layerResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly HttpRequest $request
    ) {
    }

    public function afterGetClearUrl(State $subject, string $result): string
    {
        $layer = $this->layerResolver->get();
        if (!$this->urlBuilder->isApplicable($layer)) {
            return $result;
        }

        $storeId = (int)$this->storeManager->getStore()->getId();
        $query = $this->request->getQuery()->toArray();
        unset($query['p']);

        // "cat" and the decimal sliders are not path attributes, so clearing
        // only the path codes left them applied.
        foreach ($layer->getState()->getFilters() as $item) {
            unset($query[$item->getFilter()->getRequestVar()]);
        }
        foreach ($this->slugProvider->getFilterableCodes($storeId) as $code) {
            unset($query[$code]);
        }

        return $this->urlBuilder->build($layer->getCurrentCategory(), [], $query) ?? $result;
    }
}
