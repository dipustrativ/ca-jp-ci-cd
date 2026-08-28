<?php
declare(strict_types=1);

namespace CarpetAvenue\Orderify\Plugins\Adminhtml\Tax;

use Magento\Framework\App\RequestInterface;
use Magento\Tax\Model\TaxCalculation;
use Magento\Tax\Api\Data\QuoteDetailsInterface;

class TaxCalculationCountryPlugin
{
    public function __construct(
        private readonly RequestInterface $request
    ) {}

    public function aroundCalculateTax(
        TaxCalculation $subject,
        \Closure $proceed,
        QuoteDetailsInterface $quoteDetails,
        $storeId = null,
        $round = true
    ) {
        // Only act if our admin JS injected these params
        $shipCountry = strtoupper(trim((string)$this->request->getParam('ca_ship_country')));
        $billCountry = strtoupper(trim((string)$this->request->getParam('ca_bill_country')));
        $sameAsBilling = ((string)$this->request->getParam('ca_shipping_same_as_billing') === '1');

        $countryId = $sameAsBilling ? $billCountry : $shipCountry;
        $countryId = strtoupper(trim((string)$countryId));

        if ($countryId !== '' && strlen($countryId) === 2) {
            $shipping = $quoteDetails->getShippingAddress();
            $billing  = $quoteDetails->getBillingAddress();

            if ($shipping) {
                $shipping->setCountryId($countryId);
                $shipping->setRegion(null);
            }
            if ($sameAsBilling && $billing) {
                $billing->setCountryId($countryId);
                $billing->setRegion(null);
            }
        }

        return $proceed($quoteDetails, $storeId, $round);
    }
}
