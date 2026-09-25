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

namespace CarpetAvenue\ExportImport\Model\Document;

/**
 * Keeps MSI from deducting stock for documents the importer writes.
 *
 * MSI observes `sales_order_shipment_save_after` (SourceDeductionProcessor) and
 * `sales_order_invoice_save_after` (VirtualSourceDeductionProcessor) and deducts
 * source stock for every new document. An imported document is history: its
 * stock left the warehouse when it was first created on the source system, so
 * deducting again here would double-count.
 *
 * It also failed outright: SourceDeductionProcessor takes the source from the
 * shipment's `source_code` extension attribute, falling back to the default
 * source only in single-source mode. An imported shipment has no source code and
 * this install has several sources, so `$sourceCode` was never assigned, the
 * "Undefined variable" warning became an exception, and ShipmentRepository
 * reported it as "The shipment couldn't be saved."
 *
 * Saves run through {@see run()} skip both observers — see
 * {@see \CarpetAvenue\ExportImport\Plugin\SkipImportedDocumentStockDeduction}.
 * Shared instance, so the importer and the plugin see the same flag.
 */
class StockDeductionGuard
{
    private int $depth = 0;

    /**
     * Run $callback with MSI's document stock deduction switched off.
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
