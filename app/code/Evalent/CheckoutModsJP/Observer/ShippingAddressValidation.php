<?php

namespace Evalent\CheckoutModsJP\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;

/**
 * Some orders arrive with missing shipping-address fields; copy them over from
 * the billing address so the order can still be created cleanly.
 */
class ShippingAddressValidation implements ObserverInterface
{
    private array $fieldsToValidate = [
        'firstname',
        'lastname',
        'street',
        'city',
        'postcode',
        'telephone'
    ];

    /**
     * @param Observer $observer
     */
    public function execute(Observer $observer)
    {
        try {
            /** @var Quote $quote */
            $quote = $observer->getQuote();
            $shippingAddress = $quote->getShippingAddress();
            $billingAddress = $quote->getBillingAddress();
            foreach ($this->fieldsToValidate as $field) {
                if (empty($shippingAddress->getData($field)) && !empty($billingAddress->getData($field))) {
                    $shippingAddress->setData($field, $billingAddress->getData($field));
                }
            }
            $quote->setShippingAddress($shippingAddress);
        } catch (\Exception $e) {
            // Swallow and let the order create as normal.
        }
    }
}
