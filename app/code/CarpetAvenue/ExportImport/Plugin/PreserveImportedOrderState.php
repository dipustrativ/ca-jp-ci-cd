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

namespace CarpetAvenue\ExportImport\Plugin;

use CarpetAvenue\ExportImport\Model\Order\StateGuard;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Handler\State;

/**
 * Skips Magento's automatic order-state adjustment while an ExportImport save is
 * in progress, so the order keeps the state/status the CSV gave it. Why that is
 * needed is on {@see StateGuard}.
 */
class PreserveImportedOrderState
{
    public function __construct(
        private readonly StateGuard $stateGuard,
    ) {
    }

    /**
     * @param State $subject
     * @param callable $proceed
     * @param Order $order
     * @return State
     */
    public function aroundCheck(State $subject, callable $proceed, Order $order): State
    {
        if ($this->stateGuard->isActive()) {
            return $subject;
        }
        return $proceed($order);
    }
}
