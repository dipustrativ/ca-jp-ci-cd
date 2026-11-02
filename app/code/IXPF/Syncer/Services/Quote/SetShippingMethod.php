<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Quote;

use Exception;
use IXPF\Syncer\Helper\Logger\QuoteLogger;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\App\Emulation;

/**
 * Set / update the shipping method on an existing quote and recollect totals,
 * mirroring the admin order-create shipping selection. Returns the full quote
 * summary (same shape as Create/Update) with shipping reflected in totals.
 */
class SetShippingMethod
{
    public function __construct(
        protected readonly CartRepositoryInterface $cartRepository,
        protected readonly Emulation               $emulation,
        protected readonly QuoteLogger             $logger,
        protected readonly CustomerGroupTaxScope   $customerGroupTaxScope
    ) {
    }

    /**
     * @throws Exception
     */
    public function process(int $quoteId, string $method): array
    {
        $method = trim($method);
        if ($method === '') {
            throw new Exception('shipping_method is required');
        }

        $quote   = $this->cartRepository->get($quoteId);
        $storeId = (int)$quote->getStoreId();

        if (!$quote->getIsActive()) {
            throw new Exception("Quote #$quoteId is not active (already ordered?)");
        }
        if ($quote->isVirtual()) {
            throw new Exception("Quote #$quoteId is virtual — no shipping method");
        }

        $this->emulation->startEnvironmentEmulation($storeId, 'frontend', true);
        try {
            $quote->setIsSuperMode(true);
            $this->customerGroupTaxScope->apply($quote);
            $address = $quote->getShippingAddress();

            if (!$address->getCountryId()) {
                throw new Exception('Set a shipping address before choosing a shipping method');
            }

            // 1. Collect the full rate list first (collectTotals populates
            //    free_method_weight, which weight-based carriers require).
            $address->setCollectShippingRates(true);
            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();

            // 2. Validate the requested method against the collected rates.
            if (!$address->getShippingRateByCode($method)) {
                throw new Exception("Shipping method '$method' is not available for this quote");
            }

            // 3. Apply it and recollect so the shipping amount lands in totals.
            $address->setShippingMethod($method);
            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();

            $this->cartRepository->save($quote);
        } finally {
            $this->customerGroupTaxScope->release();
            $this->emulation->stopEnvironmentEmulation();
        }

        $this->logger->writeDaily("[quote_shipping] Quote #$quoteId — shipping method set to '$method'");

        $result = QuoteBuilder::summarizeQuote($quote);
        $result['shipping_method'] = $method;
        $result['mode'] = 'shipping_method_set';
        return $result;
    }
}
