<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\Block\Search;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Template;
use Magento\Search\Helper\Data as SearchHelper;

/**
 * Renders the search suggestion dropdown fragment. Data is set by
 * CarpetAvenue\Productify\Controller\Search\Suggest.
 *
 * @method string getQuery()
 * @method int getTotal()
 * @method array getCategories()
 * @method int getCategoriesTotal()
 * @method array getSuggestions()
 * @method array getPages()
 */
class Suggest extends Template
{
    /**
     * PLP image id — its cached files already exist, so no rug is resized on the
     * critical path of a keystroke.
     *
     * ponytail: reusing the 640x840 listing render and scaling it down in CSS.
     * Add a dedicated ~120px image id (plus catalog:images:resize) only if the
     * payload shows up in the waterfall.
     */
    private const IMAGE_ID = 'category_page_grid';

    public function __construct(
        Template\Context $context,
        private readonly ImageHelper $imageHelper,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly SearchHelper $searchHelper,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return \Magento\Catalog\Model\ResourceModel\Product\Collection|array
     */
    public function getProducts()
    {
        return $this->getData('products') ?: [];
    }

    /**
     * Hits actually hydrated — the index can hold ids the collection then filters
     * out (other store, disabled), so the grid must count rows, not index hits.
     */
    public function getProductCount(): int
    {
        return count($this->getProducts());
    }

    public function hasResults(): bool
    {
        return $this->getProductCount() > 0
            || $this->getCategories()
            || $this->getSuggestions()
            || $this->getPages();
    }

    /**
     * The results page rejects anything shorter than catalog/search/min_query_length
     * ("Minimum Search query length is 3"), so below that the footer link is a
     * dead end — the dropdown still suggests from 2 characters.
     */
    public function canShowAll(): bool
    {
        return $this->getProductCount() > 0
            && mb_strlen((string) $this->getQuery()) >= (int) $this->searchHelper->getMinQueryLength();
    }

    public function getImageUrl(Product $product): string
    {
        return (string) $this->imageHelper->init($product, self::IMAGE_ID)->getUrl();
    }

    /**
     * Final price, formatted through the store currency so the JPY ceiling and
     * hidden .00 plugins apply exactly as they do on the listing.
     *
     * Read off the price index (SuggestProvider calls addPriceData()) rather
     * than the price model: the suggestion collection only selects the handful
     * of attributes the dropdown prints, so `special_price` isn't loaded and
     * the model would price every discounted rug at full price. The index is
     * also what the listing and the results page display, catalog rules and
     * tier prices included.
     */
    public function getFinalPrice(Product $product): string
    {
        return $this->format((float) $product->getData('final_price'));
    }

    /**
     * Struck-through regular price, or '' when the product is not discounted.
     */
    public function getOldPrice(Product $product): string
    {
        $regular = (float) $product->getData('price');

        return $regular > (float) $product->getData('final_price') ? $this->format($regular) : '';
    }

    /**
     * The matched part of a label, so the template can bold it.
     * Returns [matched, rest].
     *
     * @return array{0: string, 1: string}
     */
    public function splitMatch(string $label): array
    {
        $query = (string) $this->getQuery();
        $position = mb_stripos($label, $query);

        if ($position === false) {
            return ['', $label];
        }

        return [
            mb_substr($label, $position, mb_strlen($query)),
            mb_substr($label, $position + mb_strlen($query)),
        ];
    }

    /**
     * Prefix before the highlighted match (empty for the common prefix case).
     */
    public function matchPrefix(string $label): string
    {
        $position = mb_stripos($label, (string) $this->getQuery());

        return $position ? mb_substr($label, 0, $position) : '';
    }

    public function getResultsUrl(): string
    {
        return $this->getUrl('catalogsearch/result', ['_query' => ['q' => $this->getQuery()]]);
    }

    /**
     * Index prices are in the base currency, so they have to be converted, not
     * just formatted — and the conversion is where the JPY ceiling lands
     * (Plugin\Currency\JPCurrencyConvert wraps Currency::convert()).
     */
    private function format(float $value): string
    {
        return (string) $this->priceCurrency->convertAndFormat($value, false);
    }
}
