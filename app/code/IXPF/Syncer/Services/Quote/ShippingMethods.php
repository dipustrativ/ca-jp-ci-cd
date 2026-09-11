<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Quote;

use Exception;
use IXPF\Syncer\Services\Quote\QuoteBuilder;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\App\Emulation;

/**
 * Collect available shipping rates for a quote's shipping address,
 * mirroring the admin order-create "Get shipping methods" panel.
 */
class ShippingMethods
{
    public function __construct(
        protected readonly CartRepositoryInterface $cartRepository,
        protected readonly Emulation               $emulation,
        protected readonly PriceCurrencyInterface  $priceCurrency,
        protected readonly CustomerGroupTaxScope   $customerGroupTaxScope
    ) {
    }

    /**
     * @throws Exception
     */
    public function
    process(int $quoteId): array
    {
        $quote = $this->cartRepository->get($quoteId);

        if ($quote->isVirtual()) {
            return [
                'quote_id'   => $quoteId,
                'is_virtual' => true,
                'methods'    => [],
                'totals'     => QuoteBuilder::buildTotals($quote),
            ];
        }

        $storeId = (int)$quote->getStoreId();
        $store   = $quote->getStore();

        $this->emulation->startEnvironmentEmulation($storeId, 'frontend', true);
        try {
            $quote->setIsSuperMode(true);
            $this->customerGroupTaxScope->apply($quote);
            $address = $quote->getShippingAddress();
            // Let the Shipping total collector run: it populates free_method_weight
            // (weight-based carriers like matrixrate rate on this, NOT package weight)
            // and collects the rates. Calling collectShippingRates() directly skips
            // free_method_weight and yields no rates for weight-based carriers.
            $address->setCollectShippingRates(true);
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $this->cartRepository->save($quote);

            $methods = [];
            foreach ($address->getGroupedAllShippingRates() as $rates) {
                foreach ($rates as $rate) {
                    $methods[] = [
                        'code'          => $rate->getCarrier() . '_' . $rate->getMethod(),
                        'carrier_code'  => $rate->getCarrier(),
                        'method_code'   => $rate->getMethod(),
                        'carrier_title' => $rate->getCarrierTitle(),
                        'method_title'  => $rate->getMethodTitle(),
                        // $rate->getPrice() is the raw carrier price in the store
                        // BASE currency. The admin "Create New Order" shipping panel
                        // shows it converted to the store display currency
                        // (priceCurrency->convert). Match that for admin parity —
                        // e.g. a PLN store on an EUR base shows 100 EUR as ~473 PLN.
                        'price'         => (float)$this->priceCurrency->convert(
                            (float)$rate->getPrice(),
                            $store
                        ),
                    ];
                }
            }
        } finally {
            $this->customerGroupTaxScope->release();
            $this->emulation->stopEnvironmentEmulation();
        }

        return [
            'quote_id'   => $quoteId,
            'is_virtual' => false,
            'methods'    => $methods,
            'totals'     => QuoteBuilder::buildTotals($quote),
        ];
    }
}
