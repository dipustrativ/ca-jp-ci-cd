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

namespace CarpetAvenue\ExportImport\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

class Config
{
    private const XML_PATH_ENABLED = 'exportimport/general/enabled';
    private const XML_PATH_BATCH_SIZE = 'exportimport/general/batch_size';
    private const XML_PATH_SYNC_ORDER_TOTALS = 'exportimport/general/sync_order_totals';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    public function getBatchSize(): int
    {
        $size = (int)$this->scopeConfig->getValue(self::XML_PATH_BATCH_SIZE);
        return $size > 0 ? $size : 500;
    }

    /**
     * Whether importing documents should recompute the figures the parent order
     * derives from them — `total_invoiced`, `total_paid`, `total_refunded` and
     * the per-item invoiced / refunded quantities for invoices and credit memos,
     * `qty_shipped` for shipments.
     *
     * One switch for every document type: which figures a given import touches
     * is decided by the synchroniser wired to that type in di.xml.
     *
     * On by default: without it an imported invoice leaves the order showing
     * "Total Paid 0" and an imported shipment leaves every item at 0 shipped.
     * Turn it off only when the order CSV is the authority for those figures and
     * must not be touched.
     */
    public function isSyncOrderTotalsEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_SYNC_ORDER_TOTALS);
    }
}
