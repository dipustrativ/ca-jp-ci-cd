<?php

declare(strict_types=1);

namespace Evalent\InvoicePaymentMods\Plugin\Model\Order\Email\Container;

use Evalent\InvoicePaymentMods\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\ScopeInterface;

class InvoiceIdentity
{

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    private $request;

    /**
     * @var \Evalent\InvoicePaymentMods\Helper\Data
     */
    private $helper;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;


    public function __construct(
        RequestInterface $request,
        ScopeConfigInterface $scopeConfig,
        Data $helper
    ) {
        $this->request = $request;
        $this->helper = $helper;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @param \Magento\Sales\Model\Order\Email\Container\InvoiceIdentity $subject
     * @param                                                            $result
     *
     * @return mixed
     */
    public function afterGetTemplateId(\Magento\Sales\Model\Order\Email\Container\InvoiceIdentity $subject, $result)
    {
        $fullActionName = $this->request->getFullActionName();
        if (strpos($fullActionName, 'receipt') || strpos($fullActionName, 'capture')) {
            return $this->scopeConfig->getValue('payment/evinvoicepayment/email_template_receipt', ScopeInterface::SCOPE_STORE, $subject->getStore()->getStoreId());
        }
        return $result;
    }

    /**
     * @param \Magento\Sales\Model\Order\Email\Container\InvoiceIdentity $subject
     * @param                                                            $result
     *
     * @return mixed
     */
    public function afterGetGuestTemplateId(\Magento\Sales\Model\Order\Email\Container\InvoiceIdentity $subject, $result)
    {
        $fullActionName = $this->request->getFullActionName();

        if (strpos($fullActionName, 'receipt') || strpos($fullActionName, 'capture')) {
            return $this->scopeConfig->getValue('payment/evinvoicepayment/email_template_receipt_guest', ScopeInterface::SCOPE_STORE, $subject->getStore()->getStoreId());
        }
        return $result;
    }
}