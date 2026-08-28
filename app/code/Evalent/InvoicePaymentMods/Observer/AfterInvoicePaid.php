<?php

declare(strict_types=1);

namespace Evalent\InvoicePaymentMods\Observer;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class AfterInvoicePaid implements ObserverInterface
{

    /**
     * @var \Magento\Sales\Api\InvoiceManagementInterface
     */
    private $invoiceManagement;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    private $request;

    public function __construct(
        RequestInterface $request,
        \Magento\Sales\Api\InvoiceManagementInterface $invoiceManagement
    )
    {
        $this->invoiceManagement = $invoiceManagement;
        $this->request = $request;
    }

    /**
     * @param Observer $observer
     *
     * @return void
     */
    public function execute(Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order\Invoice $invoice */
        $invoice = $observer->getInvoice();
        $fullActionName = $this->request->getFullActionName();
        if (strpos($fullActionName, 'capture')) {
            $this->invoiceManagement->notify($invoice->getEntityId());
        }
    }
}