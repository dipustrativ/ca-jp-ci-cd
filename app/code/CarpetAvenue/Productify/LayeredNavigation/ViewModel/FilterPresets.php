<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\LayeredNavigation\ViewModel;

use CarpetAvenue\Productify\LayeredNavigation\Model\Layer\Filter\SliderFilterInterface;
use Magento\Catalog\Model\Layer\Filter\FilterInterface;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Quick-pick presets shown under the price and size sliders in the filter modal.
 *
 * A preset does not navigate. The template hands its range to the slider widget,
 * which stages it exactly as dragging the handles would, so the count in the
 * "Show" button updates and nothing is applied until the shopper confirms.
 *
 * Ported from carpetavenue.jp's Evalent_CarpetFinder PriceStep and SizeStep
 * blocks, which we do not have — the bands below are their tables verbatim.
 */
class FilterPresets implements ArgumentInterface
{
    /**
     * Round numbers per currency: [lower band, upper band]. Anything not listed
     * falls back to "default".
     */
    private const PRICE_LEVELS = [
        'default' => [400, 1000],
        'JPY'     => [50000, 130000],
        'SEK'     => [4000, 10000],
        'DKK'     => [4000, 10000],
        'NOK'     => [4000, 10000],
        'USD'     => [400, 1000],
        'PLN'     => [2000, 5000],
        'HUF'     => [200000, 400000],
        'CHF'     => [400, 1000],
    ];

    /**
     * Width and length bands in centimetres. Each preset drives both sliders.
     * The top band deliberately overshoots our catalogue; jQuery UI clamps it
     * to the slider maximum, which is what "and larger" should select.
     */
    private const SIZE_LEVELS = [
        '<100x150 cm' => ['width' => [0, 100],     'length' => [0, 150]],
        '100x150 cm'  => ['width' => [90, 110],    'length' => [135, 165]],
        '140x200 cm'  => ['width' => [120, 160],   'length' => [180, 220]],
        '160x230 cm'  => ['width' => [128, 192],   'length' => [184, 276]],
        '200x300 cm'  => ['width' => [180, 220],   'length' => [240, 360]],
        '>200x300 cm' => ['width' => [200, 10000], 'length' => [300, 10000]],
    ];

    public function __construct(
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly LayerResolver $layerResolver
    ) {
    }

    /**
     * Hidden once a price filter is applied: the presets are a starting point,
     * and leaving them up invites overwriting a range the shopper just set.
     */
    public function showPricePresets(): bool
    {
        foreach ($this->layerResolver->get()->getState()->getFilters() as $item) {
            if ($item->getFilter()->getRequestVar() === 'price') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, array{label: string, from: float, to: float}>
     */
    public function getPricePresets(SliderFilterInterface $filter): array
    {
        $config = $filter->getSliderConfig();
        $min = (float)$config['min'];
        $max = (float)$config['max'];
        [$lower, $upper] = self::PRICE_LEVELS[$this->getCurrencyCode()] ?? self::PRICE_LEVELS['default'];

        return [
            [
                'label' => (string)__('Up to %1', $this->format($lower)),
                'from'  => $min,
                'to'    => (float)$lower,
            ],
            [
                'label' => (string)__('%1 - %2', $this->format($lower), $this->format($upper)),
                'from'  => (float)$lower,
                'to'    => (float)$upper,
            ],
            [
                'label' => (string)__('Over %1', $this->format($upper)),
                'from'  => (float)$upper,
                'to'    => $max,
            ],
            [
                'label' => (string)__('Show All'),
                'from'  => $min,
                'to'    => $max,
            ],
        ];
    }

    /**
     * @return array<int, array{label: string, width: array{0: int, 1: int}, length: array{0: int, 1: int}}>
     */
    public function getSizePresets(): array
    {
        $presets = [];

        foreach (self::SIZE_LEVELS as $label => $bands) {
            $presets[] = [
                'label'  => (string)__($label),
                'width'  => $bands['width'],
                'length' => $bands['length'],
            ];
        }

        return $presets;
    }

    /**
     * The single row behind the カーペット販売中 filter.
     *
     * am_on_sale is a boolean attribute, so the layer offers two options, Yes and
     * No. Live shows one row, so we take the Yes and label it with the filter's
     * own name rather than "Yes".
     *
     * @return array{label: string, count: int, url: string, selected: bool}|null
     */
    public function getOnSaleItem(FilterInterface $filter): ?array
    {
        foreach ($filter->getItems() as $item) {
            if ((string)$item->getValue() !== '1') {
                continue;
            }

            $selected = method_exists($item, 'isSelected') && $item->isSelected();

            return [
                'label'    => (string)__('On Sale'),
                'count'    => (int)$item->getCount(),
                'url'      => (string)($selected ? $item->getRemoveUrl() : $item->getUrl()),
                'selected' => $selected,
            ];
        }

        return null;
    }

    private function format(int $value): string
    {
        return (string)$this->priceCurrency->format($value, false);
    }

    private function getCurrencyCode(): string
    {
        return (string)$this->priceCurrency->getCurrency()->getCode();
    }
}
