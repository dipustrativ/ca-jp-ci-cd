<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Keeps `jpy_lowest_sale_price` in step with `cost` on every product save —
 * admin form, ERP onboarding, and any other path that calls $product->save().
 *
 * Runs on save_before so the value goes in as part of the same save: one write
 * instead of two, and no risk of re-entering the save cycle.
 *
 * Paths that write attributes directly (ProductAction::updateAttributes) never
 * fire this — those are covered by
 * IXPF\CarpetAvenueJP\Plugin\Catalog\RecalculateJpyLowestSalePrice.
 *
 * @package IXPF_CarpetAvenueJP
 */

declare(strict_types=1);

namespace IXPF\CarpetAvenueJP\Observer\Product;

use IXPF\CarpetAvenueJP\Model\Product\JpyLowestSalePrice;
use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class SetJpyLowestSalePrice implements ObserverInterface
{
    public function __construct(
        private readonly JpyLowestSalePrice $jpyLowestSalePrice
    ) {
    }

    public function execute(Observer $observer): void
    {
        // getData('product'), not the magic getProduct(): same value, but a
        // real method call that static analysis can follow.
        $product = $observer->getEvent()->getData('product');
        if (!$product instanceof Product) {
            return;
        }

        // No store-scope check on purpose: both `cost` and
        // `jpy_lowest_sale_price` are global attributes (is_global = 1), so
        // Magento persists them to store 0 whatever scope the save happens in.
        // Guarding on $product->getStoreId() === 0 looks right but silently
        // skips most saves — a product loaded through the repository carries
        // store_id 1 here, not 0.
        $cost = $product->getData('cost');
        if ($cost === null || $cost === '' || (float)$cost <= 0) {
            return;
        }

        $product->setData(
            JpyLowestSalePrice::ATTRIBUTE_CODE,
            $this->jpyLowestSalePrice->calculate((float)$cost)
        );
    }
}
