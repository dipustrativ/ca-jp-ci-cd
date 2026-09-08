<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\LayeredNavigation\Model\Layer\Filter;

use CarpetAvenue\Productify\LayeredNavigation\Model\Config;
use CarpetAvenue\Productify\LayeredNavigation\Model\Search\FacetProvider;
use Magento\Catalog\Model\Category as CategoryModel;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Filter\Item\DataBuilder as ItemDataBuilder;
use Magento\Catalog\Model\Layer\Filter\ItemFactory as FilterItemFactory;
use Magento\Catalog\Model\ResourceModel\Layer\Filter\DecimalFactory;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Decimal attribute filter (e.g. width2/height2 carpet dimensions) rendered as
 * a from-to range slider with a unit label ("cm"), matching the reference
 * Amasty setup. The stock decimal filter formats values as currency and lists
 * fixed range buckets — wrong for physical dimensions.
 *
 * In slider mode the item list is replaced by one synthetic item that the
 * shared renderer template turns into the slider (same contract as Price).
 */
class Decimal extends \Magento\CatalogSearch\Model\Layer\Filter\Decimal implements SliderFilterInterface
{
    private const BOUNDS_CACHE_PREFIX = 'PRODUCTIFY_DECIMAL_BOUNDS_';

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
        DecimalFactory $filterDecimalFactory,
        PriceCurrencyInterface $priceCurrency,
        private readonly Config $moduleConfig,
        private readonly FacetProvider $facetProvider,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly ResourceConnection $resource,
        private readonly string $unitsLabel = 'cm',
        array $data = []
    ) {
        parent::__construct(
            $filterItemFactory,
            $storeManager,
            $layer,
            $itemDataBuilder,
            $filterDecimalFactory,
            $priceCurrency,
            $data
        );
    }

    /**
     * @inheritdoc
     */
    public function apply(RequestInterface $request)
    {
        $param = $request->getParam($this->getRequestVar());
        if (is_string($param) && $param !== '' && preg_match('/^\d*(\.\d+)?-\d*(\.\d+)?$/', $param)) {
            [$from, $to] = explode('-', $param, 2);
            $this->currentRange = [$from === '' ? null : (float)$from, $to === '' ? null : (float)$to];
        }

        return parent::apply($request);
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
     * @inheritdoc  Dimensions are centimetres — the parent formats them as
     * currency ("€157 - €170").
     */
    protected function renderRangeLabel($fromPrice, $toPrice)
    {
        $units = $this->unitsLabel !== '' ? ' ' . $this->unitsLabel : '';

        if ($toPrice === null) {
            return __('%1%2 and above', $this->formatBound($fromPrice), $units);
        }

        return __('%1 - %2%3', $this->formatBound($fromPrice), $this->formatBound($toPrice), $units);
    }

    /**
     * @param float|string $value
     */
    private function formatBound($value): string
    {
        return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
    }

    /**
     * @inheritdoc
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
            'units' => $this->unitsLabel,
        ];
    }

    /**
     * Min/max of the attribute across the current result set, excluding this
     * filter's own applied range so the slider does not collapse.
     *
     * Exact values come from the decimal EAV table scoped to the products in
     * the current result set — NOT the search facet buckets. Those buckets are
     * coarse (interval-wide), so their lowest "from"/highest "to" round to the
     * bucket edge: a set whose real range is 40-680 sits in the 0_100..600_700
     * buckets and the slider read 0-700. Mirrors how Price uses the exact
     * getMinPrice()/getMaxPrice() rather than bucket edges.
     *
     * @return array{0: float, 1: float}
     */
    private function getSliderBounds(): array
    {
        if ($this->bounds !== null) {
            return $this->bounds;
        }

        $code = $this->getAttributeModel()->getAttributeCode();

        $cacheKey = $this->getBoundsCacheKey($code);
        if ($cacheKey !== null) {
            $cached = $this->cache->load($cacheKey);
            if ($cached !== false && $cached !== null) {
                $decoded = $this->serializer->unserialize($cached);
                if (is_array($decoded) && count($decoded) === 2) {
                    return $this->bounds = [(float)$decoded[0], (float)$decoded[1]];
                }
            }
        }

        // Always the stateless collection — never the live layer collection.
        // The latter shares the singleton SearchCriteriaBuilder, so calling
        // getAllIds() on it mid-render empties the shared criteria and the page
        // 500s with EmptyRequestDataException. The stateless one has isolated
        // builders. Excluding this filter's own code is a no-op when it isn't
        // applied and keeps the slider from collapsing when it is.
        $collection = $this->facetProvider->createStatelessCollection($this->getLayer(), $code);

        $min = 0.0;
        $max = 0.0;
        try {
            $productIds = $collection !== null ? $collection->getAllIds() : [];

            if ($productIds !== []) {
                $connection = $this->resource->getConnection();
                $select = $connection->select()
                    ->from(
                        $this->resource->getTableName('catalog_product_entity_decimal'),
                        ['min_value' => 'MIN(value)', 'max_value' => 'MAX(value)']
                    )
                    ->where('attribute_id = ?', (int)$this->getAttributeModel()->getId())
                    ->where('store_id IN (?)', [0, (int)$this->_storeManager->getStore()->getId()])
                    ->where('entity_id IN (?)', $productIds)
                    ->where('value IS NOT NULL');

                $row = $connection->fetchRow($select);
                $min = (float)($row['min_value'] ?? 0.0);
                $max = (float)($row['max_value'] ?? 0.0);
            }
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
     * Mirrors Price::getBoundsCacheKey() — null when there is no category.
     */
    private function getBoundsCacheKey(string $code): ?string
    {
        $layer = $this->getLayer();
        if (!$layer->getCurrentCategory() || !$layer->getCurrentCategory()->getId()) {
            return null;
        }

        return self::BOUNDS_CACHE_PREFIX . $code . '_' . $layer->getStateKey() . '_'
            . $this->facetProvider->getStateSignature($layer, $code);
    }
}
