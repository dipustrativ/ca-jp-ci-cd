<?php

declare(strict_types=1);

namespace Evalent\InvoicePaymentMods\Model\EvInvoiceConfigProvider;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class EvInvoiceConfigProvider extends \Evalent\InvoicePaymentMods\Model\EvInvoiceConfigProvider\EvInvoiceConfigProviderBase
{

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        parent::__construct($scopeConfig);
    }

    public function getConfig()
    {
        return [
            'payment' => [
                'evinvoicepayment' => [
                    //Instructions: General instructions In path_In Core Table payment/evinvoicepayment/instructions.
                    //with the text value in field.
                    'instructions' => $this->scopeConfigObject->getValue('payment/evinvoicepayment/instructions', ScopeInterface::SCOPE_STORE),
                    //Checkaddress: Check if Shipping anf billing must be the same address In path_In Core Table payment/evinvoicepayment/checkaddress.
                    'checkaddress' => $this->scopeConfigObject->getValue('payment/evinvoicepayment/checkaddress'),
                    //Checkaddressinstructions: Shipping anf billing instructions In path In Core Table payment/evinvoicepayment/checkaddressinstructions
                    //with the text value in field.
                    'checkaddressinstructions' => $this->scopeConfigObject->getValue('payment/evinvoicepayment/checkaddressinstructions', ScopeInterface::SCOPE_STORE)
                ]
            ]
        ];
    }
}