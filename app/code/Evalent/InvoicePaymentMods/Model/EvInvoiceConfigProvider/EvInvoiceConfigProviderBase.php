<?php

namespace Evalent\InvoicePaymentMods\Model\EvInvoiceConfigProvider;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Class EvInvoiceConfigProvider
 * @package Evalent\InvoicePaymentMods\Model\EvInvoiceConfigProvider
 */
class EvInvoiceConfigProviderBase implements ConfigProviderInterface
{
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfigObject;

    /**
     * InvoiceConfigProvider constructor.
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig
    )
    {
        $this->scopeConfigObject = $scopeConfig;
    }

    /**
     * @return array
     */
    public function getConfig()
    {
        return [
            'payment' => [
                'evinvoicepayment' => [
                    //Instructions: General instructions In path_In Core Table payment/evinvoicepayment/instructions.
                    //with the text value in field.
                    'instructions' => trim($this->scopeConfigObject->getValue('payment/evinvoicepayment/instructions')),
                    //Checkaddress: Check if Shipping anf billing must be the same address In path_In Core Table payment/evinvoicepayment/checkaddress.
                    'checkaddress' => $this->scopeConfigObject->getValue('payment/evinvoicepayment/checkaddress'),
                    //Checkaddressinstructions: Shipping anf billing instructions In path In Core Table payment/evinvoicepayment/checkaddressinstructions
                    //with the text value in field.
                    'checkaddressinstructions' => trim($this->scopeConfigObject->getValue('payment/evinvoicepayment/checkaddressinstructions'))
                ]
            ]
        ];
    }
}