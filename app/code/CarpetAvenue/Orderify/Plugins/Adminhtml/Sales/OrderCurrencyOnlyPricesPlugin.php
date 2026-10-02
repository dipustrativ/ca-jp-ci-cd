<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Plugins\Adminhtml\Sales;

use Magento\Sales\Helper\Admin;
use Magento\Sales\Model\Order;

/**
 * Admin order / invoice / credit memo views: show amounts in the order currency only.
 *
 * Base currency is EUR while orders are placed and captured in JPY, so stock
 * displayPrices() rendered "<strong>€448.39</strong><br/>[¥76,850]" on every item
 * row and total. Render the order-currency amount alone instead.
 */
class OrderCurrencyOnlyPricesPlugin
{
    /**
     * @param Admin $subject
     * @param callable $proceed
     * @param mixed $dataObject
     * @param float|string|null $basePrice
     * @param float|string|null $price
     * @param bool $strong
     * @param string $separator
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundDisplayPrices(
        Admin $subject,
        callable $proceed,
        $dataObject,
        $basePrice,
        $price,
        $strong = false,
        $separator = '<br/>'
    ) {
        $order = $dataObject instanceof Order ? $dataObject : $dataObject?->getOrder();

        if (!$order instanceof Order || !$order->isCurrencyDifferent()) {
            return $proceed($dataObject, $basePrice, $price, $strong, $separator);
        }

        $result = $order->formatPrice($price);

        return $strong ? '<strong>' . $result . '</strong>' : $result;
    }
}
