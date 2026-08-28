<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Order
 * @package   CarpetAvenue_Orderify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Service\AdyenInvoice;

use Exception;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates automatic invoice processing of Adyen orders: selects eligible
 * orders, verifies payment via locally synchronized Adyen data and either
 * creates an invoice (smart capture) or flags the order with a single comment.
 */
class Processor
{
    /**
     * Payment additional_information key marking an order as already checked.
     * Also used verbatim in the OrderProvider NOT LIKE exclusion.
     */
    public const CHECKED_FLAG_KEY = 'adyen_auto_invoice_checked_at';

    private const COMMENT_SUCCESS =
        'Invoice was automatically created by the system cron after successful payment '
        . 'verification using synchronized Adyen webhook data.';

    private const COMMENT_NO_PAYMENT =
        'System cron verified the locally synchronized Adyen payment information, '
        . 'but no successful payment was found. Invoice was not created.';

    private const COMMENT_PROCESSING_FAILED =
        'System cron attempted automatic invoice creation after successful Adyen payment '
        . 'verification, but processing failed. Manual review required.';

    public function __construct(
        private readonly OrderProvider $orderProvider,
        private readonly PaymentVerifier $paymentVerifier,
        private readonly InvoiceCreator $invoiceCreator,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly TransactionFactory $transactionFactory,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{processed: int, invoiced: int, skipped: int, deferred: int, errors: int}
     */
    public function process(): array
    {
        $stats = ['processed' => 0, 'invoiced' => 0, 'skipped' => 0, 'deferred' => 0, 'errors' => 0];

        $orders = $this->orderProvider->getOrders();
        $this->logger->info(sprintf('AdyenAutoInvoice: %d order(s) selected for processing.', count($orders)));

        foreach ($orders as $order) {
            $stats['processed']++;

            try {
                $outcome = $this->processOrder($order);
                $stats[$outcome]++;
            } catch (Exception $exception) {
                $stats['errors']++;
                $this->logger->error(sprintf(
                    'AdyenAutoInvoice: order %s failed: %s',
                    $order->getIncrementId(),
                    $exception->getMessage()
                ), ['exception' => $exception]);
                $this->markCheckedSafely($order);
            }
        }

        $this->logger->info('AdyenAutoInvoice: run finished. ' . json_encode($stats));

        return $stats;
    }

    /**
     * @return string one of: invoiced | skipped | deferred
     * @throws Exception
     */
    private function processOrder(Order $order): string
    {
        if ($order->hasInvoices()) {
            $this->logger->info(sprintf(
                'AdyenAutoInvoice: order %s already has an invoice — skipping.',
                $order->getIncrementId()
            ));

            return 'skipped';
        }

        $result = $this->paymentVerifier->verify($order);

        $this->logger->info(sprintf(
            'AdyenAutoInvoice: order %s (state: %s, method: %s) → %s — %s',
            $order->getIncrementId(),
            $order->getState(),
            $order->getPayment() ? $order->getPayment()->getMethod() : 'n/a',
            $result->status,
            $result->reason
        ));

        if ($result->isDefer()) {
            return 'deferred';
        }

        if (!$result->isSuccess()) {
            $this->markChecked($order, self::COMMENT_NO_PAYMENT);

            return 'skipped';
        }

        if (!$order->canInvoice()) {
            $this->logger->warning(sprintf(
                'AdyenAutoInvoice: order %s has successful payment but cannot be invoiced (state: %s, status: %s).',
                $order->getIncrementId(),
                $order->getState(),
                $order->getStatus()
            ));
            $this->markChecked($order, self::COMMENT_NO_PAYMENT);

            return 'skipped';
        }

        $captureCase = $result->status === VerificationResult::SUCCESS_CAPTURED
            ? Invoice::CAPTURE_OFFLINE
            : Invoice::CAPTURE_ONLINE;

        $invoice = $this->invoiceCreator->create($order, $captureCase);

        // Online capture is asynchronous at Adyen, so core payment flow parks the order
        // in payment_review until the CAPTURE webhook confirms. Business decision: trust
        // the accepted capture request and present the order as processing immediately.
        // NOTE: pair this with the SkipHoldWhenCaptured plugin so a genuinely-captured
        // order cannot be wrongly held by a late/duplicate negative webhook.
        if ($order->getState() === Order::STATE_PAYMENT_REVIEW) {
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING));
        }

        $order->getPayment()->setAdditionalInformation(self::CHECKED_FLAG_KEY, $this->dateTime->gmtDate());
        $order->addCommentToStatusHistory(self::COMMENT_SUCCESS);
        $order->setIsInProcess(true);

        $transaction = $this->transactionFactory->create();
        $transaction->addObject($invoice);
        $transaction->addObject($order);
        $transaction->save();

        $this->logger->info(sprintf(
            'AdyenAutoInvoice: order %s invoiced (#%s, capture: %s, grand total: %s).',
            $order->getIncrementId(),
            $invoice->getIncrementId(),
            $captureCase,
            $invoice->getGrandTotal()
        ));

        return 'invoiced';
    }

    /**
     * Flags the order as checked and adds a single history comment.
     * Orders already flagged are left untouched — no duplicate comments even
     * though flagged orders may be re-selected on later runs.
     */
    private function markChecked(Order $order, string $comment): void
    {
        if ($order->getPayment()->getAdditionalInformation(self::CHECKED_FLAG_KEY)) {
            return;
        }

        $order->getPayment()->setAdditionalInformation(self::CHECKED_FLAG_KEY, $this->dateTime->gmtDate());
        $order->addCommentToStatusHistory($comment);
        $this->orderRepository->save($order);
    }

    /**
     * Best-effort flag after a processing exception so a poison order cannot
     * spam a comment on every cron run. If even this save fails, the order
     * retries naturally until the lookback window expires.
     */
    private function markCheckedSafely(Order $order): void
    {
        try {
            if ($order->getPayment()) {
                $this->markChecked($order, self::COMMENT_PROCESSING_FAILED);
            }
        } catch (Exception $exception) {
            $this->logger->error(sprintf(
                'AdyenAutoInvoice: could not flag order %s after failure: %s',
                $order->getIncrementId(),
                $exception->getMessage()
            ));
        }
    }
}
