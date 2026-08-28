<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Order
 * @package   CarpetAvenue_Orderify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Service\AdyenInvoice;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;

/**
 * Creates and registers a full invoice for an order with the given capture case.
 * Persisting the invoice/order is the caller's responsibility (single transaction).
 */
class InvoiceCreator
{
    public function __construct(
        private readonly InvoiceService $invoiceService
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function create(Order $order, string $captureCase): Invoice
    {
        $invoice = $this->invoiceService->prepareInvoice($order);

        if (!$invoice || !$invoice->getTotalQty()) {
            throw new LocalizedException(
                __('Cannot create an invoice without items for order #%1.', $order->getIncrementId())
            );
        }

        $invoice->setRequestedCaptureCase($captureCase);
        $invoice->register();

        return $invoice;
    }
}
