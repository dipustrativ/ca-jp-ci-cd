<?php

namespace IXPF\Syncer\Controller\Adminhtml\Forward;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;

class Index extends Action
{
    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    public function _processUrlKeys()
    {
        return true;
    }

    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create();

        // Use the session form key instead of generating new one
        $formKey = $this->_session->getFormKey();

        if ($invoice = $this->getRequest()->getParam('invoice')) {
            $redirect->setPath('sales/invoice/view', ['invoice_id' => $invoice, 'key' => $formKey]);
            return $redirect;
        }

        if ($refund = $this->getRequest()->getParam('refund')) {
            $redirect->setPath('sales/creditmemo/view', ['creditmemo_id' => $refund, 'key' => $formKey]);
            return $redirect;
        }

        if ($order = $this->getRequest()->getParam('order')) {
            $redirect->setPath('sales/order/view', ['order_id' => $order, 'key' => $formKey]);
            return $redirect;
        }

        if ($customer = $this->getRequest()->getParam('customer')) {
            $redirect->setPath('customer/index/edit', ['id' => $customer, 'key' => $formKey]);
            return $redirect;
        }

        if ($product = $this->getRequest()->getParam('product')) {
            $redirect->setPath('catalog/product/edit', ['id' => $product, 'key' => $formKey]);
            return $redirect;
        }

        $redirect->setPath("admin/dashboard");
        return $redirect;
    }
}