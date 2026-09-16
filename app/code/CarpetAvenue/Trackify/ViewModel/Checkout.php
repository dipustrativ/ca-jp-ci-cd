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
use Magento\Quote\Model\Quote\Item as QuoteItem;
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

        $items = [];
        $index = 0;

        foreach ($quote->getAllVisibleItems() as $item) {
            $items[] = $this->getQuoteItem($item, $index++);
        }

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

    /**
     * @param QuoteItem $item
     * @param int $index
     * @return array
     */
    private function getQuoteItem(QuoteItem $item, int $index): array
    {
        return [
            'item_id' => (string) $item->getSku(),
            'item_name' => (string) $item->getName(),
            'price' => $this->dataLayer->getQuoteItemPrice($item),
            'quantity' => (int) $item->getQty(),
            'index' => $index,
        ];
    }
}
