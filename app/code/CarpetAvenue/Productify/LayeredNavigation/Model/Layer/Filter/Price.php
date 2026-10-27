<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\LayeredNavigation\Model\Layer\Filter;

use CarpetAvenue\Productify\LayeredNavigation\Model\Config;
use CarpetAvenue\Productify\LayeredNavigation\Model\Search\FacetProvider;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Filter\DataProvider\PriceFactory;
use Magento\Catalog\Model\Layer\Filter\Dynamic\AlgorithmFactory;
use Magento\Catalog\Model\Layer\Filter\Item\DataBuilder as ItemDataBuilder;
use Magento\Catalog\Model\Layer\Filter\ItemFactory as FilterItemFactory;
use Magento\Catalog\Model\Category as CategoryModel;
use Magento\Catalog\Model\ResourceModel\Layer\Filter\Price as PriceResource;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Search\Dynamic\Algorithm;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Price filter rendered as a range slider. Accepts the stock "from-to" URL
 * format (?price=100-500). In slider mode the item list is replaced by one
 * synthetic item that the shared renderer template turns into the slider.
 */
class Price extends \Magento\CatalogSearch\Model\Layer\Filter\Price implements SliderFilterInterface
{
    public const SLIDER_ITEM_LABEL = SliderFilterInterface::SLIDER_ITEM_LABEL;

    private const BOUNDS_CACHE_PREFIX = 'PRODUCTIFY_PRICE_BOUNDS_';

    /** Backstop only — the category cache tags do the real invalidation. */
    private const BOUNDS_CACHE_LIFETIME = 3600;

    /** @var array{0: ?float, 1: ?float}|null */
    private ?array $currentRange = null;

    /** @var array{0: float, 1: float}|null */
    private ?array $bounds = null;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        FilterItemFactory $filterItemFactory,
        StoreManagerInterface $storeManager,
        Layer $layer,
        ItemDataBuilder $itemDataBuilder,
        PriceResource $resource,
        CustomerSession $customerSession,
        Algorithm $priceAlgorithm,
        PriceCurrencyInterface $priceCurrency,
        AlgorithmFactory $algorithmFactory,
        PriceFactory $dataProviderFactory,
        private readonly Config $moduleConfig,
        private readonly FacetProvider $facetProvider,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        array $data = []
    ) {
        parent::__construct(
            $filterItemFactory,
            $storeManager,
            $layer,
            $itemDataBuilder,
            $resource,
            $customerSession,
            $priceAlgorithm,
            $priceCurrency,
            $algorithmFactory,
            $dataProviderFactory,
            $data
        );
    }

    /**
     * @inheritdoc
     *
     * ?price= carries STORE currency, because that is what the slider shows and
     * what the shopper types — live uses the same (?price=7754.00-165000.00 in
     * JPY). The price index behind addFieldToFilter('price', ...) holds BASE
     * currency, and core passes the request values straight through, so every
     * bound was compared against base figures: with EUR base and JPY display at
     * 170, a ¥13,591–¥2,749,948 slider asked the index for €13,591–€2,749,948.
     * Only the few products above €13,591 came back, and raising the minimum
     * past the base-currency maximum returned nothing at all.
     *
     * Core offers no seam between "value used for the query" and "value used for
     * the label", so apply() is reimplemented rather than delegated: the range is
     * converted to base for the collection while the state item — which feeds the
     * chip label and its remove URL — keeps the store-currency values.
     */
    public function apply(RequestInterface $request)
    {
        $param = $request->getParam($this->getRequestVar());
        if (!is_string($param) || $param === '' || !preg_match('/^\d*(\.\d+)?-\d*(\.\d+)?$/', $param)) {
            // Not a range we recognise (comma-separated drilldown, junk) — core
            // knows what to do with it, currency mismatch and all.
            return parent::apply($request);
        }

        [$from, $to] = explode('-', $param, 2);
        $this->currentRange = [$from === '' ? null : (float)$from, $to === '' ? null : (float)$to];

        $rate = $this->getStoreCurrencyRate();
        $baseFrom = $from === '' ? '' : (float)$from / $rate;
        $baseTo = $to === '' ? '' : (float)$to / $rate;

        $this->getLayer()->getProductCollection()->addFieldToFilter(
            'price',
            [
                'from' => $baseFrom,
                // Core's delta keeps the upper bound exclusive so adjacent
                // ranges don't both claim a product sitting exactly on it.
                'to' => $baseTo === '' || $baseFrom == $baseTo ? $baseTo : $baseTo - self::PRICE_DELTA,
            ]
        );

        $this->getLayer()->getState()->addFilter(
            $this->_createItem(
                // Core's label renderer multiplies by the currency rate itself,
                // so it takes the base figures, not the store-currency ones.
                $this->_renderRangeLabel($baseFrom === '' ? 0 : $baseFrom, $baseTo),
                [$from, $to]
            )
        );

        return $this;
    }

    /**
     * Base → store currency rate (1.0 if unset, so a misconfigured store filters
     * on raw base values rather than dividing everything to nothing).
     */
    private function getStoreCurrencyRate(): float
    {
        try {
            $rate = (float)$this->_storeManager->getStore($this->getStoreId())->getCurrentCurrencyRate();
        } catch (\Exception $e) {
            return 1.0;
        }

        return $rate > 0 ? $rate : 1.0;
    }

    /**
     * @inheritdoc
     */
    protected function _getItemsData()
    {
        if (!$this->moduleConfig->isSliderEnabled()) {
            return parent::_getItemsData();
        }

        [$min, $max] = $this->getSliderBounds();

        // Nothing to slide over and nothing applied — hide the filter.
        if ($max <= $min && $this->currentRange === null) {
            return [];
        }

        return [
            [
                'label' => self::SLIDER_ITEM_LABEL,
                'value' => $this->currentRange !== null
                    ? ($this->currentRange[0] ?? '') . '-' . ($this->currentRange[1] ?? '')
                    : '',
                'count' => 0,
            ],
        ];
    }

    /**
     * Slider config for the frontend widget.
     *
     * @return array{min: float, max: float, from: float, to: float, step: float, requestVar: string}
     */
    public function getSliderConfig(): array
    {
        [$min, $max] = $this->getSliderBounds();

        return [
            'min' => $min,
            'max' => $max,
            'from' => max($min, $this->currentRange[0] ?? $min),
            'to' => min($max, $this->currentRange[1] ?? $max),
            'step' => $this->moduleConfig->getSliderStep(),
            'requestVar' => $this->getRequestVar(),
            // Per-store currency symbol (€, $, ¥, kr …), not a hardcoded sign.
            'units' => (string) $this->_storeManager->getStore()->getCurrentCurrency()->getCurrencySymbol(),
        ];
    }

    /**
     * Min/max of the current result set.
     *
     * Excludes this price filter itself so the slider does not collapse to the
     * selected range.
     *
     * Always off a stateless collection, never the layer's own. By the time the
     * filters render, the toolbar has already put the page size on the layer
     * collection, and getMinPrice()/getMaxPrice() aggregate over the collection's
     * select — so the handles spanned whatever 50 products page 1 happened to
     * hold. On this category that read ¥13,591–¥2,749,948 against a true
     * ¥2,040–¥4,471,451, hiding 51 products below the lower handle and 1 above
     * the upper one. The bounds cache key ignores pagination (correctly — bounds
     * are a property of the result set, not the page), so page 2 then re-served
     * page 1's numbers and the error looked stable rather than obviously wrong.
     *
     * @return array{0: float, 1: float}
     */
    private function getSliderBounds(): array
    {
        if ($this->bounds !== null) {
            return $this->bounds;
        }

        $cacheKey = $this->getBoundsCacheKey();
        if ($cacheKey !== null) {
            $cached = $this->cache->load($cacheKey);
            if ($cached !== false && $cached !== null) {
                $decoded = $this->serializer->unserialize($cached);
                if (is_array($decoded) && count($decoded) === 2) {
                    return $this->bounds = [(float)$decoded[0], (float)$decoded[1]];
                }
            }
        }

        $collection = $this->facetProvider->createStatelessCollection(
            $this->getLayer(),
            $this->getAttributeModel()->getAttributeCode()
        );

        try {
            if ($collection !== null) {
                //  Resolve the search first. A fulltext collection only puts its
                //  `entity_id IN (...)` clause on the select once the search has
                //  run, and getMinPrice()/getMaxPrice() aggregate straight off
                //  that select — ask them too early and every attribute filter is
                //  missing from the SQL, so the handles span the whole category
                //  however narrow the current result set is.
                $collection->getSize();
            }

            $min = $collection !== null ? (float)$collection->getMinPrice() : 0.0;
            $max = $collection !== null ? (float)$collection->getMaxPrice() : 0.0;
        } catch (\Exception $e) {
            $min = 0.0;
            $max = 0.0;
        }

        $bounds = [floor($min), ceil($max)];

        if ($cacheKey !== null) {
            $categoryId = (int)$this->getLayer()->getCurrentCategory()->getId();
            $this->cache->save(
                $this->serializer->serialize($bounds),
                $cacheKey,
                [CategoryModel::CACHE_TAG . '_' . $categoryId, CategoryModel::CACHE_TAG],
                self::BOUNDS_CACHE_LIFETIME
            );
        }

        return $this->bounds = $bounds;
    }

    /**
     * getStateKey() is store + category + customer group but omits the applied
     * filters, so the state signature is appended — without it only the bare
     * category could be cached and every filtered view recomputed its bounds.
     *
     * @return string|null
     */
    private function getBoundsCacheKey(): ?string
    {
        $layer = $this->getLayer();
        if (!$layer->getCurrentCategory() || !$layer->getCurrentCategory()->getId()) {
            return null;
        }

        return self::BOUNDS_CACHE_PREFIX . $layer->getStateKey() . '_'
            . $this->facetProvider->getStateSignature($layer, (string)$this->getRequestVar());
    }
}
