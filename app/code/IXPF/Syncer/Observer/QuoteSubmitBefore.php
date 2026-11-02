<?php
declare(strict_types=1);

namespace IXPF\Syncer\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;

/**
 * Carries the API's send_email decision from the quote onto the order.
 *
 * IXPF\Syncer\Services\Quote\QuoteSubmit sets self::QUOTE_FLAG on the quote before
 * submitting it. Core's SubmitObserver reads the order's canSendNewEmailFlag on
 * submit_success, so the flag has to be on the *order* by then — this observer runs
 * on sales_model_service_quote_submit_before, the one point where both objects exist
 * and the order has not been placed yet.
 *
 * Letting core send the mail (rather than sending it here) is deliberate: an explicit
 * send in the service would double-send whenever send_email is true.
 *
 * A quote without the flag is left alone, so storefront checkout keeps Magento's own
 * behaviour.
 */
class QuoteSubmitBefore implements ObserverInterface
{
    public const QUOTE_FLAG = 'ixpf_can_send_email';

    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getQuote();
        if ($quote === null || !$quote->hasData(self::QUOTE_FLAG)) {
            return; // not an IXPF submit — leave normal checkout emails alone
        }

        $order = $observer->getEvent()->getOrder();
        if ($order !== null) {
            $order->setCanSendNewEmailFlag((bool)$quote->getData(self::QUOTE_FLAG));
        }
    }
}
