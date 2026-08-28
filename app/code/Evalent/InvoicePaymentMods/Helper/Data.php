<?php
/**
 * Created by PhpStorm.
 * User: bassam
 * Date: 2018-10-30
 * Time: 10:46
 */

namespace Evalent\InvoicePaymentMods\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;

/**
 * Class Data
 * @package Evalent\InvoicePaymentMods\Helper
 */
class Data extends AbstractHelper
{
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     *
     * @var CollectionFactory
     */
    protected $invoiceCollectionFactory;

    /**
     *
     * @var InvoiceService
     */
    protected $invoiceService;

    /**
     *
     * @var TransactionFactory
     */
    protected $transactionFactory;

    /**
     * @var InvoiceSender
     */
    protected $invoiceSender;

    /**
     * Data constructor.
     * @param Context $context
     * @param ScopeConfigInterface $scopeConfig
     * @param CollectionFactory $invoiceCollectionFactory
     * @param InvoiceService $invoiceService
     * @param TransactionFactory $transactionFactory
     * @param InvoiceSender $invoiceSender
     */
    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        CollectionFactory $invoiceCollectionFactory,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        InvoiceSender $invoiceSender
    ) {
        parent::__construct($context);
        $this->scopeConfig = $scopeConfig;
        $this->invoiceCollectionFactory = $invoiceCollectionFactory;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->invoiceSender = $invoiceSender;
    }

    /**
     * @param $order
     * @return Invoice|null
     */
    public function createInvoice($order)
    {
        try {
            $invoices = $this->invoiceCollectionFactory->create()
                ->addAttributeToFilter('order_id', array('eq' => $order->getId()));
            $invoices->getSelect()->limit(1);

            if ((int)$invoices->count() !== 0) {
                return null;
            }

            if (!$order->canInvoice()) {
                return null;
            }
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setState(Invoice::STATE_OPEN);
            /*The values In /magento/vendor/magento/module-sales/Model/Order/Invoice.php
            const STATE_OPEN = 1;
            const STATE_PAID = 2;
            const STATE_CANCELED = 3; */
            if ($invoice->canCapture() && $this->getConfigField('invoice_status_value') == '2') {
                $invoice->capture();
                // If the admin set the value to Invoice Status (invoice_status_value in system.xml) to Paid
                //The Config source options in
                // Evalent/InvoicePaymentMethod/Model/Config/Source/Invoicestatus/InvoiceStatusValues.php
                // So if Invoice Status => Paid => capture.
            }
            // complete the process and register the invoice.
            $invoice->register();
            $order->setCustomerNoteNotify($this->getConfigField('auto_send_invoice'));
            $order->setIsInProcess(true);
            $order->addStatusHistoryComment('Automatically INVOICED', false);
            $transactionSave = $this->transactionFactory->create()
                ->addObject($invoice)
                ->addObject($invoice->getOrder());
            $transactionSave->save();


            if ($this->getConfigField('auto_send_invoice')) {

                /**
                 *
                 * Sends order invoice email to the customer.
                 *
                 * Email will be sent immediately in two cases:
                 *
                 * - if asynchronous email sending is disabled in global settings
                 * - if $forceSyncMode parameter is set to TRUE
                 *
                 * Otherwise, email will be sent later during running of
                 * corresponding cron job.
                 *
                 */
                if (!$this->invoiceSender->send($invoice, true)) {
                    throw new \Exception('Invoice email has not sent.');
                }
            }
        } catch (\Exception $e) {
            $order->addStatusHistoryComment('Exception message2: ' . $e->getMessage(), false);
            $order->save();
            return null;
        }
        return $invoice;
    }

    /**
     * @param $field
     * @return mixed
     */
    public function getConfigField($field)
    {
        return $this->scopeConfig->getValue('payment/evinvoicepayment/' . $field);
    }
}