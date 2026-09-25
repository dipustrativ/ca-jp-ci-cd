<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Order
 * @package   CarpetAvenue_ExportImport
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\ExportImport\Model\Order;

/**
 * Keeps an imported order on the state/status the importer gave it.
 *
 * Every `orderRepository->save()` runs
 * `Magento\Sales\Model\ResourceModel\Order\Handler\State::check()` from the order
 * resource model's `_beforeSave()`. That handler re-derives the state from the
 * order's aggregates, and an imported order's aggregates do not tell the story
 * the CSV does: the order CSV carries no `total_paid` (lifecycle aggregates are
 * out of scope, see {@see Schema}), so `canCreditmemo()` is false, and with every
 * item's `qty_shipped` filled in the handler rewrites `complete` to
 * `closed/closed` on the very save that creates the order.
 *
 * Saves run through {@see run()} skip that handler — see
 * {@see \CarpetAvenue\ExportImport\Plugin\PreserveImportedOrderState}. Outside
 * it the handler behaves exactly as in core. Shared instance, so the importer
 * and the plugin see the same flag.
 */
class StateGuard
{
    private int $depth = 0;

    /**
     * Run $callback with Magento's automatic order-state adjustment switched off.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        $this->depth++;
        try {
            return $callback();
        } finally {
            $this->depth--;
        }
    }

    public function isActive(): bool
    {
        return $this->depth > 0;
    }
}
