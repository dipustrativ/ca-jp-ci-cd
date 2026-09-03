<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\LayeredNavigation\ViewModel;

use CarpetAvenue\Productify\LayeredNavigation\Model\Config;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class FilterConfig implements ArgumentInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Json $json,
        private readonly LayerResolver $layerResolver,
        private readonly UrlInterface $url,
        private readonly RequestInterface $request
    ) {
    }

    public function isAjaxEnabled(): bool
    {
        return $this->config->isAjaxEnabled();
    }

    /**
     * Number of applied filter values, for the mobile filter button's badge.
     * Counts values rather than attributes, so it always matches the number of
     * chips on screen — which is what carpetavenue.jp badges.
     */
    public function getAppliedFilterCount(): int
    {
        return count($this->layerResolver->get()->getState()->getFilters());
    }

    public function getJsonConfig(): string
    {
        return $this->json->serialize([
            'filterBlock' => '.block.filter',
            'mainColumn' => '.column.main',
            'linkSelectors' => implode(', ', [
                '.block.filter .am-filter-link[href]',
                '.block.filter .am-chip-remove',
                '.block.filter .filter-clear',
                '.block.filter .swatch-option-link-layered',
            ]),
            // Batch apply: stage selections, apply on "Show" click.
            'batchApply' => $this->config->isBatchApplyEnabled(),
            'baseUrl' => $this->getBaseUrl(),
            'pageParam' => 'p',
            // Total result count in the fetched page (last toolbar number).
            'countSource' => '.toolbar-number',
            'countTarget' => '.am-items',
        ]);
    }

    /**
     * Filter-free URL of the current listing (category page or search results)
     * used as the base for building the staged, combined filter URL client-side.
     * Filters are re-added as query params, which the layer reads in every mode.
     */
    private function getBaseUrl(): string
    {
        if ($this->request->getModuleName() === 'catalogsearch') {
            $query = $this->request->getParam('q');
            $params = ['_use_rewrite' => true, '_escape' => false];
            $params['_query'] = ($query !== null && $query !== '') ? ['q' => $query] : [];

            return $this->url->getUrl('catalogsearch/result/index', $params);
        }

        $category = $this->layerResolver->get()->getCurrentCategory();

        return $category && $category->getId()
            ? $category->getUrl()
            : $this->url->getUrl('');
    }
}
