<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\LayeredNavigation\Model\Layer\Filter;

use CarpetAvenue\Productify\LayeredNavigation\Model\Config;
use CarpetAvenue\Productify\LayeredNavigation\Model\Search\FacetProvider;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Filter\Item\DataBuilder as ItemDataBuilder;
use Magento\Catalog\Model\Layer\Filter\ItemFactory as FilterItemFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\StateException;
use Magento\Framework\Filter\StripTags;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Multiselect attribute filter: accepts comma-separated option ids
 * (?color=5,6 → OR within the attribute), keeps the option list visible after
 * applying, and keeps sibling option counts correct via FacetProvider.
 */
class Attribute extends \Magento\CatalogSearch\Model\Layer\Filter\Attribute
{
    private bool $isApplied = false;

    /** @var string[] */
    private array $appliedValues = [];

    /** @var array<int, array<string, mixed>>|null */
    private ?array $selectOptions = null;

    public function __construct(
        FilterItemFactory $filterItemFactory,
        StoreManagerInterface $storeManager,
        Layer $layer,
        ItemDataBuilder $itemDataBuilder,
        private readonly StripTags $stripTags,
        private readonly FacetProvider $facetProvider,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct(
            $filterItemFactory,
            $storeManager,
            $layer,
            $itemDataBuilder,
            $stripTags,
            $data
        );
    }

    /**
     * @inheritdoc
     */
    public function apply(RequestInterface $request)
    {
        if (!$this->config->isMultiselectEnabled()) {
            return parent::apply($request);
        }

        if ($this->isApplied) {
            return $this;
        }

        $requestValue = $request->getParam($this->_requestVar);
        if ($requestValue === null || $requestValue === '' || is_array($requestValue)) {
            return $this;
        }

        $values = $this->getValidValues((string)$requestValue);
        if ($values === []) {
            return $this;
        }

        $this->isApplied = true;
        $this->appliedValues = $values;

        $attribute = $this->getAttributeModel();
        $filterValues = $attribute->getBackendType() === 'int'
            ? array_map('intval', $values)
            : $values;

        $this->getLayer()
            ->getProductCollection()
            ->addFieldToFilter($attribute->getAttributeCode(), $filterValues);

        foreach ($values as $value) {
            $label = $this->getOptionText($value);
            $label = is_array($label) ? implode(', ', $label) : (string)$label;
            $this->getLayer()
                ->getState()
                ->addFilter($this->_createItem($label, $value));
        }

        // Unlike core, do NOT setItems([]) — the option list stays visible so
        // the shopper can add more values of the same attribute.
        return $this;
    }

    /**
     * @inheritdoc
     */
    protected function _getItemsData()
    {
        if (!$this->config->isMultiselectEnabled()) {
            return parent::_getItemsData();
        }

        $attribute = $this->getAttributeModel();
        $optionsFacetedData = $this->getFacetedDataSafe();

        // Applied options must never disappear, even with zero remaining results.
        foreach ($this->appliedValues as $value) {
            if (!isset($optionsFacetedData[$value])) {
                $optionsFacetedData[$value] = ['value' => $value, 'count' => 0];
            }
        }

        $onlyWithResults =
            $this->getAttributeIsFilterable($attribute) === static::ATTRIBUTE_OPTIONS_ONLY_WITH_RESULTS;

        if ($optionsFacetedData === [] && !$onlyWithResults) {
            return $this->itemDataBuilder->build();
        }

        foreach ($this->getSelectOptions() as $option) {
            if (empty($option['value']) && !is_numeric($option['value'])) {
                continue;
            }
            $value = (string)$option['value'];
            $count = (int)($optionsFacetedData[$value]['count'] ?? 0);
            if ($onlyWithResults && $count === 0 && !in_array($value, $this->appliedValues, true)) {
                continue;
            }
            $this->itemDataBuilder->addItemData(
                $this->stripTags->filter($option['label']),
                $value,
                $count
            );
        }

        return $this->itemDataBuilder->build();
    }

    /**
     * Facets from the main collection when this filter is not applied (free),
     * from FacetProvider (own filter excluded) when it is.
     *
     * @return array<int|string, array{value?: mixed, count: int}>
     */
    private function getFacetedDataSafe(): array
    {
        $attributeCode = $this->getAttributeModel()->getAttributeCode();

        if ($this->isApplied) {
            return $this->facetProvider->getFacetedData($this->getLayer(), $attributeCode);
        }

        try {
            return $this->getLayer()->getProductCollection()->getFacetedData($attributeCode);
        } catch (StateException $e) {
            // Attribute missing from the search index (e.g. is_filterable changed
            // without reindex) — degrade to an empty filter instead of a 500.
            return [];
        }
    }

    /**
     * @return string[] requested values that are real options of this attribute
     */
    private function getValidValues(string $requestValue): array
    {
        $requested = array_unique(
            array_filter(array_map('trim', explode(',', $requestValue)), 'strlen')
        );
        $availableOptions = array_map('strval', array_column($this->getSelectOptions(), 'value'));

        return array_values(array_intersect($requested, $availableOptions));
    }

    /**
     * Needed by both apply() and _getItemsData() — load from EAV once.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getSelectOptions(): array
    {
        return $this->selectOptions ??= $this->getAttributeModel()->getFrontend()->getSelectOptions() ?: [];
    }
}
