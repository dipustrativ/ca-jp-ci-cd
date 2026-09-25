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

use CarpetAvenue\ExportImport\Model\Document\StockDeductionGuard;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Skips MSI's source deduction observers while an ExportImport document save is
 * in progress. Why that is needed is on {@see StockDeductionGuard}.
 *
 * Declared on both Magento\InventoryShipping\Observer\SourceDeductionProcessor
 * and Magento\InventoryShipping\Observer\VirtualSourceDeductionProcessor.
 */
class SkipImportedDocumentStockDeduction
{
    public function __construct(
        private readonly StockDeductionGuard $stockDeductionGuard,
    ) {
    }

    /**
     * @param ObserverInterface $subject
     * @param callable $proceed
     * @param Observer $observer
     * @return mixed
     */
    public function aroundExecute(ObserverInterface $subject, callable $proceed, Observer $observer): mixed
    {
        if ($this->stockDeductionGuard->isActive()) {
            return null;
        }
        return $proceed($observer);
    }
}
