<?php

declare(strict_types=1);

namespace Evalent\InvoicePaymentMods\Model;

use Evalent\InvoicePaymentMods\Model\Payment\EvInvoicePayment;
use Evalent\InvoicePaymentMods\Block\Info\Instructions;
use Magento\Framework\DataObject;
use Magento\Quote\Api\Data\CartInterface;

class Payment extends EvInvoicePayment
{

    /**
     * Instructions block path
     *
     * @var string
     */
    protected $_infoBlockType = Instructions::class;


    /**
     * @param CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(?\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        $available = true;
        $_isAdmin = \Magento\Backend\App\Area\FrontNameResolver::AREA_CODE == 'adminhtml';
        if ($quote === null) {
            $available = false;
        }

        if ($this->getConfigData('allowspecific_customer')) {
            if (!$this->isCustomerGroupAllowed($quote)) {
                $available = false;
            }
        }

        if ($this->getConfigData('allowspecific')) {
            if (!$this->isCountryAllowed($quote->getBillingAddress()->getCountryId())) {
                $available = false;
            }
        }

        $checkResult = new DataObject();
        $checkResult->setData('is_available', $available);

        // for future use in observers
        $this->_eventManager->dispatch(
            'payment_method_is_active',
            [
                'result' => $checkResult,
                'method_instance' => $this,
                'quote' => $quote
            ]
        );

        return $checkResult->getData('is_available');
    }
}
