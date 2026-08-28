<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Quote;

use Exception;
use IXPF\Syncer\Helper\Logger\QuoteLogger;
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
        protected readonly QuoteLogger              $logger
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

        if ($paymentMethod === '') {
            throw new Exception('payment_method is required');
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

            if (!$quote->isVirtual()) {
                if ($shippingMethod === '') {
                    throw new Exception('shipping_method is required for a physical order');
                }
                $shipping = $quote->getShippingAddress();
                $shipping->setCollectShippingRates(true);
                $shipping->setShippingMethod($shippingMethod);
            }

            $quote->getPayment()->importData(['method' => $paymentMethod]);

            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();
            $this->cartRepository->save($quote);

            $order = $this->quoteManagement->submit($quote);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }

        if (!$order || !$order->getId()) {
            throw new Exception("Order could not be placed for quote #$quoteId");
        }

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

        if ($sendEmail) {
            try {
                $this->orderSender->send($order);
            } catch (Exception $e) {
                $this->logger->writeDaily(
                    "[quote_submit] Order #{$order->getIncrementId()} email failed: " . $e->getMessage(),
                    'error'
                );
            }
        }

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
