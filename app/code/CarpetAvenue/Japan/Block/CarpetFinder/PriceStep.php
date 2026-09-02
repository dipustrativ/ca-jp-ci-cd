<?php
declare(strict_types=1);

namespace CarpetAvenue\Japan\Block\CarpetFinder;

use CarpetAvenue\Japan\Api\PriceStepItemInterface;
use CarpetAvenue\Japan\Api\PriceStepItemInterfaceFactory;
use Magento\Framework\View\Element\Template;

class PriceStep extends AbstractStep
{
    /** @var \CarpetAvenue\Japan\Api\PriceStepItemInterfaceFactory */
    private $priceStepItemFactory;

    /** @var \Magento\Catalog\Model\Layer */
    private $layer;

    /** @var \Magento\Framework\Pricing\PriceCurrencyInterface */
    private $priceCurrency;

    /** @var \Magento\Theme\Block\Html\Pager */
    private $htmlPagerBlock;

    /**
     * Upper bound used for the "Over X" / "Show All" ranges when no filter data is available.
     * The category-page layered nav (Productify) has no Amasty FromTo filter to read a real max from,
     * so we fall back to a sensible ceiling.
     */
    const FALLBACK_UPPER_LEVEL = 20000;

    /**
     * Manually defined pretty levels for each currency
     * @var array
     */
    private $levels = [
        "default" => [400, 1000], // fallback and EUR
        "SEK" => [4000, 10000],
        "DKK" => [4000, 10000],
        "NOK" => [4000, 10000],
        "JPY" => [50000, 130000],
        "USD" => [400, 1000],
        "PLN" => [2000, 5000],
        "HUF" => [200000, 400000],
        "CHF" => [400, 1000]
    ];

    public function __construct(
        Template\Context $context,
        PriceStepItemInterfaceFactory $priceStepItemFactory,
        \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency,
        \Magento\Catalog\Model\Layer\Resolver $layerResolver,
        \Magento\Theme\Block\Html\Pager $htmlPagerBlock,
        array $data = []
    ) {
        $this->priceStepItemFactory = $priceStepItemFactory;
        $this->layer = $layerResolver->get();
        $this->priceCurrency = $priceCurrency;
        $this->htmlPagerBlock = $htmlPagerBlock;
        parent::__construct($context, $data);
    }

    /**
     * @inheritDoc
     */
    public function getPriority(): int
    {
        return (int) $this->getData("priority");
    }

    public function getAttributeCode(): string
    {
        return (string) $this->getData("attribute_code");
    }

    /**
     * @inheritDoc
     */
    public function shouldRender(): bool
    {
        // On the home / CMS context there is no category layer to inspect and
        // touching the layered-nav filter list would fire an empty search query.
        if ($this->isInCmsContext()) {
            return true;
        }

        foreach ($this->layer->getState()->getFilters() as $item) {
            if ($item->getFilter()->getRequestVar() == "price") {
                return false;
            }
        }

        return true;
    }

    protected function getUpperLevel(): int
    {
        return self::FALLBACK_UPPER_LEVEL;
    }

    /**
     * @return PriceStepItemInterface[]
     */
    public function getPriceItems(): array
    {
        $level = $this->getApplicableStepData();
        $upperLevel = $this->getUpperLevel();

        return [
            $this->priceStepItemFactory->create([
                "label" => __("Up to %1", $this->formatCurrency($level[0])),
                "url" => $this->getFilterUrl(null, $level[0]),
                "range" => $this->getPriceRangeUnconverted(null, $level[0]),
                "lower" => $this->getPriceLowerUnconverted(0),
                "upper" => $this->getPriceUpperUnconverted($level[0])
            ]),
            $this->priceStepItemFactory->create([
                "label" => __("%1 - %2", $this->formatCurrency($level[0]), $this->formatCurrency($level[1])),
                "url" => $this->getFilterUrl($level[0], $level[1]),
                "range" => $this->getPriceRangeUnconverted($level[0], $level[1]),
                "lower" => $this->getPriceLowerUnconverted($level[0]),
                "upper" => $this->getPriceUpperUnconverted($level[1])
            ]),
            $this->priceStepItemFactory->create([
                "label" => __("Over %1", $this->formatCurrency($level[1])),
                "url" => $this->getFilterUrl($level[1], null),
                "range" => $this->getPriceRangeUnconverted($level[1], null),
                "lower" => $this->getPriceLowerUnconverted($level[1]),
                "upper" => $this->getPriceUpperUnconverted($upperLevel)
            ]),
            $this->priceStepItemFactory->create([
                "label" => __("Show All"),
                "url" => $this->getFilterUrl(null, null),
                "range" => $this->getPriceRangeUnconverted(null, null),
                "lower" => $this->getPriceLowerUnconverted(0),
                "upper" => $this->getPriceUpperUnconverted($upperLevel)
            ])
        ];
    }

    /**
     * Gets the rate to convert current chosen currency to base currency
     * @return float
     */
    private function getBaseCurrencyConversionRate(): float
    {
        return round(1 / $this->priceCurrency->convert(1), 10);
    }

    /**
     * gets the level data for the current currency
     * return[0] is middle levels lower bound
     * return[1] is middle levels upper bound
     * @return array
     */
    private function getApplicableStepData(): array
    {
        return $this->levels[$this->getCurrentCurrencyCode()] ?? $this->levels["default"];
    }

    private function getCurrentCurrencyCode(): string
    {
        /** @var \Magento\Directory\Model\Currency $currency */
        $currency = $this->priceCurrency->getCurrency();

        return $currency->getCode();
    }

    private function getFilterUrl(?int $lower, ?int $upper): string
    {
        if ($lower !== null) {
            $lower *= $this->getBaseCurrencyConversionRate();
        }

        if ($upper !== null) {
            $upper *= $this->getBaseCurrencyConversionRate();
        }

        $filter = ($lower ?? "") . "-" . ($upper ?? "");

        if ($this->isInCmsContext()) {
            return $this->getFallbackCategory()->getUrl()."?price=".$filter; // yes this is ugly
        }

        return $this->getUrl(
            "*/*/*",
            [
                "_current" => true,
                '_use_rewrite' => true,
                '_query' => [
                    "price" => $filter,
                    $this->htmlPagerBlock->getPageVarName() => null // unset pager
                ]
            ]
        );
    }

    private function getPriceRangeUnconverted(?int $lower, ?int $upper): string
    {
        return ($lower ?? "0") . "-" . ($upper ?? "");
    }

    private function getPriceLowerUnconverted(?int $lower)
    {
        return ($lower ?? "0");
    }

    private function getPriceUpperUnconverted(?int $upper)
    {
        return ($upper ?? "");
    }

    private function formatCurrency($value)
    {
        return $this->priceCurrency->format($value, false);
    }
}
