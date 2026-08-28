<?php

declare(strict_types=1);

namespace Evalent\InvoicePaymentMods\Plugin;

class AddCanceledPrintButton
{


    public function beforeSetLayout(\Magento\Sales\Block\Adminhtml\Order\Invoice\View $subject, $layout)
    {
        if ($subject->getInvoice()->getId() && $subject->getInvoice()->isCanceled()) {
            $subject->addButton(
                'send_disabled_notification',
                [
                    'label' => __('Print Canceled Invoice'),
                    'class' => 'print',
                    'onclick' => 'setLocation(\'' . $subject->getPrintUrl() . "canceled/1" . '\')'
                ]
            );
        }
    }
}
