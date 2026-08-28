<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\LayeredNavigation\Model\Layer\Filter;

use CarpetAvenue\Productify\LayeredNavigation\Model\Config;
use CarpetAvenue\Productify\LayeredNavigation\Model\Seo\SlugProvider;
use CarpetAvenue\Productify\LayeredNavigation\Model\Seo\UrlBuilder;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Theme\Block\Html\Pager;

/**
 * Filter item with toggle URLs: clicking an option of a multiselect attribute
 * adds/removes its value from the comma-separated request param instead of
 * replacing the whole filter. When SEO URLs are enabled, option-attribute
 * filters become path segments (/color-red_blue) and everything else stays in
 * the query string.
 */
class Item extends \Magento\Catalog\Model\Layer\Filter\Item
{
    public function __construct(
        UrlInterface $url,
        Pager $htmlPagerBlock,
        private readonly RequestInterface $request,
        private readonly Config $config,
        private readonly UrlBuilder $seoUrlBuilder,
        private readonly SlugProvider $slugProvider,
        private readonly StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($url, $htmlPagerBlock, $data);
    }

    /**
     * @inheritdoc
     */
    public function getUrl()
    {
        if (!$this->config->isEnabled()) {
            return parent::getUrl();
        }

        $values = $this->getAppliedValues();
        $value = $this->getValueString();

        if ($this->isToggleFilter()) {
            if (in_array($value, $values, true)) {
                $values = array_values(array_diff($values, [$value]));
            } else {
                $values[] = $value;
            }
        } else {
            $values = [$value];
        }

        return $this->buildUrl($values);
    }

    /**
     * @inheritdoc
     */
    public function getRemoveUrl()
    {
        if (!$this->config->isEnabled()) {
            return parent::getRemoveUrl();
        }

        $values = $this->isToggleFilter()
            ? array_values(array_diff($this->getAppliedValues(), [$this->getValueString()]))
            : [];

        return $this->buildUrl($values);
    }

    /**
     * Whether this item's value is currently applied (used for checkbox state).
     */
    public function isSelected(): bool
    {
        return in_array($this->getValueString(), $this->getAppliedValues(), true);
    }

    /**
     * Request param this option writes to (e.g. "color", "price"). Exposed so
     * the batch-apply JS can group staged selections per attribute.
     */
    public function getRequestVar(): string
    {
        return (string)$this->getFilter()->getRequestVar();
    }

    /**
     * Whether clicking this option adds/removes a value from a comma list
     * (multiselect attribute) rather than replacing the whole filter.
     */
    public function isMultiselect(): bool
    {
        return $this->isToggleFilter();
    }

    /**
     * @inheritdoc  Price state items carry [from, to] arrays — join to "from-to".
     */
    public function getValueString()
    {
        $value = $this->getValue();

        return is_array($value) ? implode('-', $value) : (string)$value;
    }

    /**
     * Only our multiselect attribute filter toggles values; every other filter
     * type (price, decimal, category) replaces its value.
     */
    private function isToggleFilter(): bool
    {
        return $this->config->isMultiselectEnabled() && $this->getFilter() instanceof Attribute;
    }

    /**
     * @param string[] $values
     */
    private function buildUrl(array $values): string
    {
        return $this->buildSeoUrl($values) ?? $this->buildQueryUrl($values);
    }

    /**
     * @param string[] $values
     */
    private function buildQueryUrl(array $values): string
    {
        $query = [
            $this->getFilter()->getRequestVar() => $values !== [] ? implode(',', $values) : null,
            $this->_htmlPagerBlock->getPageVarName() => null,
        ];

        return $this->_url->getUrl(
            '*/*/*',
            ['_current' => true, '_use_rewrite' => true, '_query' => $query, '_escape' => true]
        );
    }

    /**
     * @param string[] $values
     */
    private function buildSeoUrl(array $values): ?string
    {
        $filter = $this->getFilter();
        $layer = $filter->getLayer();
        if (!$this->seoUrlBuilder->isApplicable($layer) || !$this->request instanceof HttpRequest) {
            return null;
        }

        $storeId = (int)$this->storeManager->getStore()->getId();
        $pathCodes = $this->slugProvider->getFilterableCodes($storeId);

        // Current filter state: path attributes from request params (covers
        // both router-injected and plain query values)...
        $attributeParams = [];
        foreach ($pathCodes as $code) {
            $param = $this->request->getParam($code);
            if (is_string($param) && $param !== '') {
                $attributeParams[$code] = $param;
            }
        }

        // ...everything else straight from the query string.
        $query = $this->request->getQuery()->toArray();
        unset($query[$this->_htmlPagerBlock->getPageVarName()]);
        foreach ($pathCodes as $code) {
            unset($query[$code]);
        }

        $own = $filter->getRequestVar();
        $ownValue = $values !== [] ? implode(',', $values) : null;
        if (in_array($own, $pathCodes, true)) {
            if ($ownValue === null) {
                unset($attributeParams[$own]);
            } else {
                $attributeParams[$own] = $ownValue;
            }
        } else {
            $query[$own] = $ownValue;
        }

        return $this->seoUrlBuilder->build($layer->getCurrentCategory(), $attributeParams, $query);
    }

    /**
     * @return string[]
     */
    private function getAppliedValues(): array
    {
        $param = $this->request->getParam($this->getFilter()->getRequestVar());
        if ($param === null || $param === '' || is_array($param)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', (string)$param)), 'strlen'));
    }
}
