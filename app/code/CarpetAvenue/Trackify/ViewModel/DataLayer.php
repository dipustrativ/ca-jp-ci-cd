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
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\SalesRule\Model\RuleFactory;
use Throwable;

/**
 * Builds GA4 ecommerce payloads for the catalog pages.
 *
 * Deliberately free of any session dependency so it can be attached to full-page-cached
 * blocks; the cart, checkout and success payloads live in ViewModel\Checkout instead.
 *
 * The quote-based builders here take their Quote as an argument rather than reading one
 * from a session, which keeps that rule intact and lets the checkout plugins — which run
 * in the webapi_rest area, where no checkout session exists — reuse them unchanged.
 */
class DataLayer implements ArgumentInterface
{
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        private readonly Data $helper,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly RuleFactory $ruleFactory
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
     * items[] for a quote. Shared by view_cart, begin_checkout, add_shipping_info,
     * add_payment_info and the promotion events, so the line shape cannot drift between
     * one funnel step and the next.
     *
     * @param iterable $quoteItems
     * @return array
     */
    public function getQuoteItems(iterable $quoteItems): array
    {
        $items = [];
        $index = 0;

        foreach ($quoteItems as $item) {
            $items[] = [
                'item_id' => (string) $item->getSku(),
                'item_name' => (string) $item->getName(),
                'price' => $this->getQuoteItemPrice($item),
                'quantity' => (int) $item->getQty(),
                'index' => $index++,
            ];
        }

        return $items;
    }

    /**
     * GA4 payload for a mid-funnel checkout step (add_shipping_info / add_payment_info).
     *
     * `value` is the sum of the items sent, matching view_cart and begin_checkout — GA4
     * defines it as the value of the items, and it is the only figure that cannot drift
     * depending on whether shipping has been collected by the time this runs.
     *
     * @param string $event
     * @param Quote $quote
     * @param array $extra e.g. shipping_tier / payment_type
     * @return array
     */
    public function getCheckoutStepPayload(string $event, Quote $quote, array $extra = []): array
    {
        $items = $this->getQuoteItems($quote->getAllVisibleItems());

        if (!$items) {
            return [];
        }

        $value = 0.0;

        foreach ($items as $line) {
            $value += $line['price'] * $line['quantity'];
        }

        $ecommerce = array_merge(
            [
                'currency' => $quote->getQuoteCurrencyCode() ?: $this->getCurrencyCode(),
                'value' => round($value, 2),
            ],
            $extra
        );

        if ($quote->getCouponCode()) {
            $ecommerce['coupon'] = (string) $quote->getCouponCode();
        }

        $ecommerce['items'] = $items;

        return ['event' => $event, 'ecommerce' => $ecommerce];
    }

    /**
     * GA4 payload for the promotion events (select_promotion / view_promotion).
     *
     * A coupon is the only promotion this storefront exposes, so the coupon IS the
     * promotion: its code becomes promotion_id and the sales rule behind it supplies the
     * readable promotion_name, without which GA4's promotion report shows "(not set)".
     *
     * Returns an empty array when no coupon is on the quote, which is what makes this
     * safe to call unconditionally from the cart and checkout templates.
     *
     * @param string $event
     * @param Quote $quote
     * @param string $slot Where it was applied — 'cart' or 'checkout'.
     * @return array
     */
    public function getPromotionPayload(string $event, Quote $quote, string $slot): array
    {
        $coupon = trim((string) $quote->getCouponCode());

        if ($coupon === '') {
            return [];
        }

        $promotion = [
            'promotion_id' => $coupon,
            'promotion_name' => $this->getAppliedRuleName($quote) ?: $coupon,
            'creative_name' => 'Coupon Code',
            'creative_slot' => $slot,
        ];

        // GA4 reads the promotion dimensions off the items, and the ecommerce object is
        // what the container's variables read — so both carry them.
        $items = array_map(
            static fn (array $line): array => array_merge($line, $promotion),
            $this->getQuoteItems($quote->getAllVisibleItems())
        );

        if (!$items) {
            return [];
        }

        return [
            'event' => $event,
            'ecommerce' => array_merge(
                $promotion,
                [
                    'currency' => $quote->getQuoteCurrencyCode() ?: $this->getCurrencyCode(),
                    'items' => $items,
                ]
            ),
        ];
    }

    /**
     * GA4 `refund` in MEASUREMENT PROTOCOL shape, built from a credit memo.
     *
     * Note the shape differs from every other builder here: the Measurement Protocol takes
     * `['name' => ..., 'params' => [...]]`, not the browser dataLayer's
     * `['event' => ..., 'ecommerce' => [...]]`. Refund is the only event this module sends
     * server-side, so it is the only one in this form.
     *
     * `value` is the credit memo's own grand total rather than the sum of the items,
     * deliberately — unlike the browser funnel events, a refund has to reverse refunded
     * shipping and tax too, and the credit memo is the only thing that knows how much of
     * each was actually given back. Amounts are read in order currency with no conversion:
     * they were fixed, ceiling included, when the order was placed.
     *
     * @param Creditmemo $creditmemo
     * @return array
     */
    public function getRefundEvent(Creditmemo $creditmemo): array
    {
        $order = $creditmemo->getOrder();

        if (!$order || !$order->getIncrementId()) {
            return [];
        }

        $items = [];

        foreach ($creditmemo->getAllItems() as $item) {
            $qty = (float) $item->getQty();

            // Credit memos carry a zero-qty line for every order item that was not
            // refunded, and configurable children ride along with their parent.
            if ($qty <= 0.0 || $item->getOrderItem()?->getParentItem()) {
                continue;
            }

            $items[] = [
                'item_id' => (string) $item->getSku(),
                'item_name' => (string) $item->getName(),
                'price' => round((float) ($item->getPriceInclTax() ?: $item->getPrice()), 2),
                'quantity' => (int) $qty,
            ];
        }

        // GA4 reads a refund carrying no items as a reversal of the ENTIRE transaction.
        // A credit memo with no refundable lines is the opposite of that — it is a
        // shipping-only or adjustment-only refund — so sending it would reverse the whole
        // order instead of the few hundred yen actually returned. There is no way to
        // express that case in GA4's model, so it is reported as nothing at all and the
        // caller logs it. (It also covers a credit memo raised against an uninvoiced
        // order, which has no lines to refund in the first place.)
        if (!$items) {
            return [];
        }

        $params = [
            'currency' => (string) $order->getOrderCurrencyCode(),
            // The credit memo's own grand total, not the sum of the items: this is what
            // reverses refunded shipping and tax as well. GA4 uses `value` for the
            // monetary reversal and `items` for the line-level detail.
            'value' => round((float) $creditmemo->getGrandTotal(), 2),
            'transaction_id' => (string) $order->getIncrementId(),
            'items' => $items,
        ];

        if ($order->getCouponCode()) {
            $params['coupon'] = (string) $order->getCouponCode();
        }

        return ['name' => 'refund', 'params' => $params];
    }

    /**
     * Name of the first sales rule the quote has applied.
     *
     * applied_rule_ids is a comma-separated list; a cart can match several rules at once
     * (a cart price rule plus the coupon), but GA4 wants one promotion name, so the first
     * is used and the coupon code is the caller's fallback. Never let a missing or
     * deleted rule cost us the event.
     *
     * @param Quote $quote
     * @return string
     */
    private function getAppliedRuleName(Quote $quote): string
    {
        $ruleIds = array_filter(explode(',', (string) $quote->getAppliedRuleIds()));

        if (!$ruleIds) {
            return '';
        }

        try {
            return (string) $this->ruleFactory->create()->load((int) reset($ruleIds))->getName();
        } catch (Throwable) {
            return '';
        }
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
