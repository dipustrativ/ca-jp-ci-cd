<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Quote;

use Exception;
use IXPF\Syncer\Helper\Logger\QuoteLogger;
use IXPF\Syncer\Observer\QuoteSubmitBefore;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Store\Model\App\Emulation;

/**
 * Place the order from a prepared quote using the same path as the admin
 * order-create screen: QuoteManagement::submit() on the in-memory quote
 * (keeps super mode / custom price runtime flags) instead of placeOrder(id),
 * which would reload the quote from the DB and drop them.
 */
class QuoteSubmit
{
    public function __construct(
        protected readonly CartRepositoryInterface $cartRepository,
        protected readonly QuoteManagement          $quoteManagement,
        protected readonly OrderSender              $orderSender,
        protected readonly OrderCommentSender       $orderCommentSender,
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly Emulation                $emulation,
        protected readonly QuoteLogger              $logger,
        protected readonly CustomerGroupTaxScope    $customerGroupTaxScope
    ) {
    }

    /**
     * @throws Exception
     */
    public function process(int $quoteId, array $payload): array
    {
        $paymentMethod  = trim((string)($payload['payment_method'] ?? ''));
        $shippingMethod = trim((string)($payload['shipping_method'] ?? ''));
        $sendEmail      = !empty($payload['send_email']);
        $comment        = trim((string)($payload['comment'] ?? ''));
        $commentVisible = !empty($payload['comment_visible_storefront']);
        $notifyComment  = !empty($payload['comment_notify_customer']);
        // Optional order email (admin parity) — becomes the order's email so all
        // order mails go there. Empty = keep the customer's email on the quote.
        $orderEmail     = trim((string)($payload['order_email'] ?? ''));

        if ($paymentMethod === '') {
            throw new Exception('payment_method is required');
        }
        if ($orderEmail !== '' && !filter_var($orderEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('order_email is not a valid email');
        }

        $quote   = $this->cartRepository->get($quoteId);
        $storeId = (int)$quote->getStoreId();

        if (!$quote->getIsActive()) {
            throw new Exception("Quote #$quoteId is not active (already ordered?)");
        }

        $this->emulation->startEnvironmentEmulation($storeId, 'frontend', true);
        try {
            $quote->setIsSuperMode(true);
            $quote->setIgnoreOldQty(true);

            // Drive the new-order email off the API's send_email flag. Core
            // SubmitObserver reads the order's canSendNewEmailFlag on
            // submit_success; QuoteSubmitBefore copies this quote flag onto the
            // order so send_email:false actually suppresses the mail (and
            // send_email:true sends it exactly once, via core).
            $quote->setData(QuoteSubmitBefore::QUOTE_FLAG, $sendEmail);

            // Keep tax on the quote's customer group for the final order totals.
            $this->customerGroupTaxScope->apply($quote);

            if (!$quote->isVirtual()) {
                $shipping = $quote->getShippingAddress();
                // Fall back to a method already set on the quote (via SetShippingMethod).
                if ($shippingMethod === '') {
                    $shippingMethod = (string)$shipping->getShippingMethod();
                }
                if ($shippingMethod === '') {
                    throw new Exception('shipping_method is required for a physical order');
                }
                $shipping->setCollectShippingRates(true);
                $shipping->setShippingMethod($shippingMethod);
            }

            // Override the order email when provided — all order mails
            // (confirmation + comment notifications) then go to this address.
            if ($orderEmail !== '') {
                $quote->setCustomerEmail($orderEmail);
                $quote->getBillingAddress()->setEmail($orderEmail);
            }

            $quote->getPayment()->importData(['method' => $paymentMethod]);

            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();
            $this->cartRepository->save($quote);

            $order = $this->quoteManagement->submit($quote);
        } finally {
            $this->customerGroupTaxScope->release();
            $this->emulation->stopEnvironmentEmulation();
        }

        if (!$order || !$order->getId()) {
            throw new Exception("Order could not be placed for quote #$quoteId");
        }

        // Mark order as placed via IXPF — internal note, not shown to customer.
        $ixpfHistory = $order->addCommentToStatusHistory('Order placed using IXPF', false, false);
        $ixpfHistory->setIsCustomerNotified(false);
        $this->orderRepository->save($order);

        if ($comment !== '') {
            $history = $order->addCommentToStatusHistory($comment, false, $commentVisible);
            $history->setIsCustomerNotified($notifyComment);
            $this->orderRepository->save($order);

            if ($notifyComment) {
                try {
                    $this->orderCommentSender->send($order, true, $comment);
                } catch (Exception $e) {
                    $this->logger->writeDaily(
                        "[quote_submit] Order #{$order->getIncrementId()} comment email failed: " . $e->getMessage(),
                        'error'
                    );
                }
            }
        }

        // Order confirmation email is handled by core SubmitObserver, gated by
        // the canSendNewEmailFlag that QuoteSubmitBefore set from $sendEmail.
        // No explicit send here — that would double-send on send_email:true.

        $this->logger->writeDaily(
            "[quote_submit] Quote #$quoteId placed as order #{$order->getIncrementId()} (grand_total {$order->getGrandTotal()})"
        );

        $tax = (float)$order->getTaxAmount();

        return [
            'quote_id'     => $quoteId,
            'order_id'     => (int)$order->getId(),
            'increment_id' => $order->getIncrementId(),
            'status'       => $order->getStatus(),
            'totals'       => [
                'coupon_code'                => (string)$order->getCouponCode(),
                'subtotal'                   => (float)$order->getSubtotal(),
                'subtotal_incl_tax'          => (float)$order->getSubtotalInclTax() ?: (float)$order->getSubtotal(),
                'discount_amount'            => (float)$order->getDiscountAmount(),
                'shipping_handling'          => (float)$order->getShippingAmount(),
                'shipping_handling_incl_tax' => (float)$order->getShippingInclTax(),
                'tax'                        => $tax,
                'grand_total_excl_tax'       => round((float)$order->getGrandTotal() - $tax, 4),
                'grand_total_incl_tax'       => (float)$order->getGrandTotal(),
            ],
        ];
    }
}
