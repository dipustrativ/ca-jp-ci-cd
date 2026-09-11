<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Quote;

use Exception;
use IXPF\Syncer\Helper\Logger\QuoteLogger;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\App\Emulation;

/**
 * Apply, change or remove a coupon on an existing quote and recollect totals,
 * mirroring the admin order-create "Apply Coupon Code" panel.
 * Pass an empty coupon to remove the current one.
 */
class ApplyCoupon
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
    public function process(int $quoteId, string $coupon): array
    {
        $coupon = trim($coupon);

        $quote   = $this->cartRepository->get($quoteId);
        $storeId = (int)$quote->getStoreId();

        if (!$quote->getIsActive()) {
            throw new Exception("Quote #$quoteId is not active (already ordered?)");
        }

        $this->emulation->startEnvironmentEmulation($storeId, 'frontend', true);
        try {
            $quote->setIsSuperMode(true);
            $this->customerGroupTaxScope->apply($quote);
            if (!$quote->isVirtual()) {
                $quote->getShippingAddress()->setCollectShippingRates(true);
            }

            $quote->setCouponCode($coupon);
            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();
            $this->cartRepository->save($quote);
        } finally {
            $this->customerGroupTaxScope->release();
            $this->emulation->stopEnvironmentEmulation();
        }

        $applied = $coupon !== '' && strcasecmp((string)$quote->getCouponCode(), $coupon) === 0;

        if ($coupon !== '' && !$applied) {
            // Magento silently drops an invalid coupon — surface it as an error
            $this->logger->writeDaily("[quote_coupon] Quote #$quoteId — coupon '$coupon' is not valid");
            throw new Exception("Coupon '$coupon' is not valid for this quote");
        }

        $this->logger->writeDaily(
            "[quote_coupon] Quote #$quoteId — coupon " . ($coupon === '' ? 'removed' : "'$coupon' applied")
        );

        // Same full shape as create/update.
        $result = QuoteBuilder::summarizeQuote($quote);
        $result['mode'] = $coupon === '' ? 'coupon_removed' : 'coupon_applied';
        return $result;
    }
}
