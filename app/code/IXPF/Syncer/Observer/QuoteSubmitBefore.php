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
    /**
     * Quote data key holding the API's send_email flag.
     */
    public const QUOTE_FLAG = 'ixpf_send_order_email';

    public function execute(Observer $observer): void
    {
        /** @var Quote|null $quote */
        $quote = $observer->getEvent()->getData('quote');
        /** @var Order|null $order */
        $order = $observer->getEvent()->getData('order');

        if (!$quote || !$order) {
            return;
        }

        $sendEmail = $quote->getData(self::QUOTE_FLAG);
        if ($sendEmail === null) {
            return;
        }

        $order->setCanSendNewEmailFlag((bool)$sendEmail);
    }
}
