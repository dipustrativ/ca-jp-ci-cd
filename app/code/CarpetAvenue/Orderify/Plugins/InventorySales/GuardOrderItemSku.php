<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Orderify\Plugins\InventorySales;

use Magento\InventorySalesApi\Model\GetSkuFromOrderItemInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Resolve an MSI source SKU for an order item whose product is gone.
 *
 * Second half of the `product_id = NULL` order-item problem fixed by
 * {@see \CarpetAvenue\Orderify\Plugins\Sales\Order\GuardItemProduct}. Core's
 * InventorySales\Model\GetSkuFromOrderItem::execute() passes the raw id into
 * GetSkusByProductIds::execute([null]) and then indexes the result by the same null —
 * two "Using null as an array offset" deprecations (PHP 8.5), which the framework
 * error handler rethrows as an exception. Kills every MSI order screen that asks an
 * item for its stock SKU (ship, invoice, credit memo, source selection).
 *
 * `sales_order_item.sku` is a stored copy of the SKU at purchase time and is the value
 * core itself falls back to when the lookup throws NoSuchEntityException — so returning
 * it keeps behaviour identical to a deleted-but-still-linked product.
 *
 * sortOrder 20 keeps this inside InventoryConfigurableProduct's own around-plugin
 * (sortOrder 0), so configurable items still resolve via `simple_sku` first.
 *
 * @see docs/Issues/ISSUE-007-order-item-null-product-id-msi-sku-deprecation.md
 */
class GuardOrderItemSku
{
    /**
     * Fall back to the order item's stored SKU when it has no product id.
     *
     * @param GetSkuFromOrderItemInterface $subject
     * @param callable $proceed
     * @param OrderItemInterface $orderItem
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundExecute(
        GetSkuFromOrderItemInterface $subject,
        callable $proceed,
        OrderItemInterface $orderItem
    ): string {
        $productId = $orderItem->getProductId();

        if ($productId === null || $productId === '') {
            return (string) $orderItem->getSku();
        }

        return $proceed($orderItem);
    }
}
