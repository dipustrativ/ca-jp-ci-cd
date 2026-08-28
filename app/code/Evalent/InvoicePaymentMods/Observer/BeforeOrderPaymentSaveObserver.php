<?php

declare(strict_types=1);

namespace Evalent\InvoicePaymentMods\Observer;

use Evalent\InvoicePaymentMods\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\OfflinePayments\Model\Banktransfer;
use Magento\OfflinePayments\Model\Cashondelivery;
use Magento\OfflinePayments\Model\Checkmo;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class BeforeOrderPaymentSaveObserver implements ObserverInterface
{

    /**
     * @var \Evalent\InvoicePaymentMods\Helper\Data
     */
    private $helper;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    public function __construct(
        Data $helper,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->helper = $helper;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Sets current instructions for bank transfer account
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $observer->getEvent()->getPayment();

        if ($payment->getMethod() == "evinvoicepayment") {
            $methodInstance = $payment->getMethodInstance();
            $methodInstance->setStore($payment->getOrder()->getStoreId());
            $payment->setAdditionalInformation(
                'instructions',
                $methodInstance->getConfigData("instructions_payment_information")
            );
//           In the rare case where Adyen data is present in the invoice payment we want to unset them
            $payment->unsAdditionalInformation(
                'brand_code',
            );
            $payment->setCcType(null);
        }
    }
}
