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

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Model\Order;

/**
 * GA4 payloads for cart, checkout and the success page.
 *
 * Split out from ViewModel\DataLayer purely so the checkout session never gets injected
 * into blocks that render on full-page-cached catalog pages. Every page this serves is
 * already cacheable="false" in core (checkout.cart, checkout.root, checkout.success),
 * so these payloads can be rendered straight into the markup.
 */
class Checkout implements ArgumentInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly DataLayer $dataLayer
    ) {
    }

    /**
     * @param string $event `view_cart` or `begin_checkout`
     * @return array
     */
    public function getQuotePayload(string $event): array
    {
        try {
            $quote = $this->checkoutSession->getQuote();
        } catch (\Exception) {
            return [];
        }

        $items = $this->dataLayer->getQuoteItems($quote->getAllVisibleItems());

        if (!$items) {
            return [];
        }

        // GA4 defines `value` here as the value of the items, not the order total — and
        // summing what we actually send is also the only figure that cannot drift: the
        // quote's grand total depends on whether shipping has been collected yet by the
        // time this block renders (it had not been on the cart page, so grand_total read
        // 42000 before shipping and 43700 after). Shipping and tax are reported on
        // `purchase`, where the order has final numbers.
        $value = 0.0;

        foreach ($items as $line) {
            $value += $line['price'] * $line['quantity'];
        }

        return [
            'event' => $event,
            'ecommerce' => [
                'currency' => $quote->getQuoteCurrencyCode() ?: $this->dataLayer->getCurrencyCode(),
                'value' => round($value, 2),
                'items' => $items,
            ],
        ];
    }

    /**
     * GA4 view_promotion for a coupon already sitting on the quote.
     *
     * select_promotion reports the ACT of applying a coupon and is queued server-side by
     * Plugin\Quote\Coupon / Observer\ApplyCouponCart. view_promotion is the other half:
     * the discount being on display when the cart or checkout page renders. Rendering it
     * from the page rather than queueing it means it repeats on every view of a
     * discounted cart, which is exactly what "viewed" should mean.
     *
     * Returns an empty array when no coupon is applied, so the template can call it
     * unconditionally.
     *
     * @param string $slot 'cart' or 'checkout'
     * @return array
     */
    public function getPromotionPayload(string $slot): array
    {
        try {
            $quote = $this->checkoutSession->getQuote();
        } catch (\Exception) {
            return [];
        }

        return $this->dataLayer->getPromotionPayload('view_promotion', $quote, $slot);
    }

    /**
     * @return array
     */
    public function getPurchasePayload(): array
    {
        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order instanceof Order || !$order->getId()) {
            return [];
        }

        $items = [];
        $index = 0;

        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = [
                'item_id' => (string) $item->getSku(),
                'item_name' => (string) $item->getName(),
                // Order amounts are stored in the order currency already, ceiling applied
                // at order time — never re-convert them.
                'price' => round((float) ($item->getPriceInclTax() ?: $item->getPrice()), 2),
                'quantity' => (int) $item->getQtyOrdered(),
                'index' => $index++,
            ];
        }

        $payload = [
            'event' => 'purchase',
            'ecommerce' => [
                'transaction_id' => (string) $order->getIncrementId(),
                'value' => round((float) $order->getGrandTotal(), 2),
                'tax' => round((float) $order->getTaxAmount(), 2),
                'shipping' => round((float) $order->getShippingInclTax(), 2),
                'currency' => (string) $order->getOrderCurrencyCode(),
                'items' => $items,
            ],
        ];

        if ($order->getCouponCode()) {
            $payload['ecommerce']['coupon'] = (string) $order->getCouponCode();
        }

        return $payload;
    }

    /**
     * Delegates to DataLayer so a template only ever needs one view model.
     *
     * @param array $payload
     * @return string
     */
    public function toJson(array $payload): string
    {
        return $this->dataLayer->toJson($payload);
    }
}
