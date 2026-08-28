<?php

declare(strict_types=1);

namespace Evalent\InvoicePaymentMods\Plugin\Block\Adminhtml\Order;

class View
{
    public function beforeSetLayout(\Magento\Sales\Block\Adminhtml\Order\Invoice\View $view)
    {
        $invoice = $view->getInvoice();
        $order = $invoice->getOrder();
        if ($invoice->getState() == 2) {

            $message = 'Are you sure you want to send a receipt to the customer?';
            $url = $view->getUrl(
                'evalent_invoice/invoice/receipt',
                ['order_id' => $order->getId(), 'invoice_id' => $invoice->getId()]
            );

            $view->addButton(
                'send_receipt',
                [
                    'label'   => __('Send Receipt'),
                    'class'   => 'myclass',
                    'onclick' => 'confirmSetLocation(\'' . __(
                            $message
                        ) . '\', \'' . $url . '\')'
                ]
            );
        }
    }
}