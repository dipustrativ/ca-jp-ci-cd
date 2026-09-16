<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Marketing
 * @package   CarpetAvenue_Trackify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Trackify\ViewModel;

use CarpetAvenue\Trackify\Helper\Data;
use Magento\Catalog\Model\Product;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Quote\Model\Quote\Item as QuoteItem;

/**
 * Builds GA4 ecommerce payloads for the catalog pages.
 *
 * Deliberately free of any session dependency so it can be attached to full-page-cached
 * blocks; the cart, checkout and success payloads live in ViewModel\Checkout instead.
 */
class DataLayer implements ArgumentInterface
{
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        private readonly Data $helper,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {
    }

    /**
     * @return string
     */
    public function getCurrencyCode(): string
    {
        return $this->helper->getCurrentCurrencyCode();
    }

    /**
     * Base-currency amount as the customer actually sees it.
     *
     * Base currency is EUR and the store displays JPY, so this has to go through
     * PriceCurrencyInterface::convert() — that is what runs Productify's
     * JPCurrencyConvert plugin and applies the JPY rounding ceiling. Catalog prices
     * already include tax (tax/calculation/price_includes_tax = 1), so no tax maths here.
     *
     * @param float $baseAmount
     * @return float
     */
    public function toDisplayPrice(float $baseAmount): float
    {
        return round((float) $this->priceCurrency->convert($baseAmount), 2);
    }

    /**
     * A GA4 `items[]` entry for a catalog product.
     *
     * @param Product $product
     * @param array $extra Merged on top, e.g. index/quantity/item_list_*.
     * @param bool $withCategories Resolve the product's own categories — one query per
     *                             product, so never switch this on inside a listing loop.
     * @return array
     */
    public function getProductItem(Product $product, array $extra = [], bool $withCategories = false): array
    {
        $item = [
            'item_id' => (string) $product->getSku(),
            'item_name' => (string) $product->getName(),
            'price' => $this->toDisplayPrice((float) $product->getFinalPrice()),
            'quantity' => 1,
        ];

        if ($withCategories) {
            $item += $this->helper->getCategoryForNames((array) $product->getCategoryIds());
        }

        return array_merge($item, $extra);
    }

    /**
     * items[] for a product listing. Categories are resolved once for the list as a
     * whole rather than per tile — 50 tiles would otherwise mean 50 category queries.
     *
     * @param iterable $products
     * @return array
     */
    public function getListItems(iterable $products): array
    {
        $items = [];
        $index = 0;

        foreach ($products as $product) {
            $items[] = $this->getProductItem($product, ['index' => $index++]);
        }

        return $items;
    }

    /**
     * GA4 payload built straight from a catalog product (add_to_wishlist).
     *
     * @param string $event
     * @param Product $product
     * @param int $qty
     * @return array
     */
    public function getProductEventPayload(string $event, Product $product, int $qty = 1): array
    {
        $price = $this->toDisplayPrice((float) $product->getFinalPrice());

        return [
            'event' => $event,
            'ecommerce' => [
                'currency' => $this->getCurrencyCode(),
                'value' => round($price * $qty, 2),
                'items' => [$this->getProductItem($product, ['quantity' => $qty], true)],
            ],
        ];
    }

    /**
     * GA4 payload for a cart mutation (add_to_cart / remove_from_cart).
     *
     * @param string $event
     * @param QuoteItem $item
     * @return array
     */
    public function getCartEventPayload(string $event, QuoteItem $item): array
    {
        $qty = (float) ($item->getQty() ?: 1);
        $price = $this->getQuoteItemPrice($item);

        return [
            'event' => $event,
            'ecommerce' => [
                'currency' => $this->getCurrencyCode(),
                'value' => round($price * $qty, 2),
                'items' => [
                    [
                        'item_id' => (string) $item->getSku(),
                        'item_name' => (string) $item->getName(),
                        'price' => $price,
                        'quantity' => (int) $qty,
                    ],
                ],
            ],
        ];
    }

    /**
     * A quote item's price in display currency.
     *
     * Quote items are read at two very different points in their lifecycle. By the time
     * an item is removed, or rendered on the cart page, totals have been collected and
     * price_incl_tax holds the quote-currency amount. On checkout_cart_product_add_after
     * they have NOT: price_incl_tax is null and getPrice() still returns the BASE (EUR)
     * amount — which is how add_to_cart first reported 123 instead of the JPY 21000 that
     * view_item reported for the same product. Fall back to the product's own final
     * price, converted exactly the way the catalog pages convert it.
     *
     * @param QuoteItem $item
     * @return float
     */
    public function getQuoteItemPrice(QuoteItem $item): float
    {
        $price = (float) $item->getPriceInclTax();

        if ($price > 0.0) {
            return round($price, 2);
        }

        $product = $item->getProduct();

        return $product ? $this->toDisplayPrice((float) $product->getFinalPrice()) : 0.0;
    }

    /**
     * @param array $payload
     * @return string
     */
    public function toJson(array $payload): string
    {
        return (string) json_encode($payload, self::JSON_FLAGS);
    }
}
