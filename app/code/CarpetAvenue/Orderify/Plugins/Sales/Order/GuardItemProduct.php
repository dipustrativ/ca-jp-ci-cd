<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Orderify\Plugins\Sales\Order;

use Magento\Sales\Model\Order\Item;

/**
 * Let an order item whose product is gone render instead of fataling.
 *
 * An order item can carry `sales_order_item.product_id = NULL` — imported historic
 * orders whose SKU was never in this catalog, or a product deleted after purchase.
 * Core's Item::getProduct() then calls ProductRepository::getById(null), which hits
 * `$this->instances[null]` — a PHP 8 "Using null as an array offset" deprecation. In
 * developer mode the error handler turns that into an exception, so the adminhtml
 * order view dies during layout generation (first casualty: InventoryShippingAdminUi's
 * ShipButton block) and the admin is bounced back to the order grid.
 *
 * Core already treats a missing product as legal — it catches NoSuchEntityException and
 * every caller null-checks the result. It just never guards a null id. Short-circuit
 * before the repository call and return the null core intended.
 *
 * @see docs/Issues/ISSUE-005-order-item-null-product-id-deprecation.md
 */
class GuardItemProduct
{
    /**
     * Skip the product lookup entirely when the item has no product id.
     *
     * @param Item $subject
     * @param callable $proceed
     * @return \Magento\Catalog\Api\Data\ProductInterface|null
     */
    public function aroundGetProduct(Item $subject, callable $proceed)
    {
        $productId = $subject->getProductId();

        if ($productId === null || $productId === '') {
            return null;
        }

        return $proceed();
    }
}
