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

use Adyen\Payment\Api\Data\OrderPaymentInterface;
use Adyen\Payment\Model\Notification;
use Adyen\Payment\Model\ResourceModel\Notification\CollectionFactory as NotificationCollectionFactory;
use Adyen\Payment\Model\ResourceModel\Order\Payment\CollectionFactory as AdyenOrderPaymentCollectionFactory;
use Magento\Sales\Model\Order;

/**
 * Decides whether an Adyen order is safe to auto-invoice using ONLY locally
 * synchronized Adyen data (webhook notifications + adyen_order_payment rows).
 * Never calls the Adyen API.
 *
 * Data sources, most to least authoritative:
 *  1. adyen_order_payment — rows exist only after a successful AUTHORISATION
 *     webhook has been fully processed by the Adyen module.
 *  2. adyen_notification — webhooks received, possibly not yet processed.
 *  3. payment additional_information resultCode — checkout-time snapshot;
 *     never trusted alone as proof of payment.
 */
class PaymentVerifier
{
    public function __construct(
        private readonly AdyenOrderPaymentCollectionFactory $adyenOrderPaymentCollectionFactory,
        private readonly NotificationCollectionFactory $notificationCollectionFactory
    ) {
    }

    public function verify(Order $order): VerificationResult
    {
        $payment = $order->getPayment();
        if ($payment === null || !$payment->getEntityId()) {
            return new VerificationResult(VerificationResult::FAIL, 'Order has no payment record');
        }

        $resultCode = (string)$payment->getAdditionalInformation('resultCode');

        if ($this->hasSuccessfulNotification($order->getIncrementId(), Notification::REFUND)) {
            return new VerificationResult(
                VerificationResult::FAIL,
                'A successful REFUND notification exists — manual review required'
            );
        }

        $adyenPayments = $this->adyenOrderPaymentCollectionFactory->create()
            ->addPaymentFilterAscending((string)$payment->getEntityId())
            ->getItems();

        if (count($adyenPayments) > 0) {
            return $this->verifyByCaptureStatus($adyenPayments, $resultCode);
        }

        return $this->verifyByAuthorisationNotification($order->getIncrementId(), $resultCode);
    }

    /**
     * @param OrderPaymentInterface[] $adyenPayments
     */
    private function verifyByCaptureStatus(array $adyenPayments, string $resultCode): VerificationResult
    {
        $captured = 0;
        $notCaptured = 0;
        $partial = 0;

        foreach ($adyenPayments as $adyenPayment) {
            $captureStatus = (string)$adyenPayment->getCaptureStatus();
            match (true) {
                in_array($captureStatus, [
                    OrderPaymentInterface::CAPTURE_STATUS_AUTO_CAPTURE,
                    OrderPaymentInterface::CAPTURE_STATUS_MANUAL_CAPTURE,
                ], true) => $captured++,
                $captureStatus === OrderPaymentInterface::CAPTURE_STATUS_PARTIAL_CAPTURE => $partial++,
                default => $notCaptured++,
            };
        }

        $total = count($adyenPayments);

        if ($partial > 0) {
            return new VerificationResult(
                VerificationResult::FAIL,
                'Partially captured payment — manual review required'
            );
        }

        if ($captured === $total) {
            return new VerificationResult(
                VerificationResult::SUCCESS_CAPTURED,
                sprintf('All %d Adyen payment(s) captured (resultCode: %s)', $total, $resultCode ?: 'n/a')
            );
        }

        if ($notCaptured === $total) {
            return new VerificationResult(
                VerificationResult::SUCCESS_AUTHORISED,
                sprintf('Authorised, not captured yet (resultCode: %s)', $resultCode ?: 'n/a')
            );
        }

        return new VerificationResult(
            VerificationResult::FAIL,
            'Mixed capture statuses across Adyen payments — manual review required'
        );
    }

    private function verifyByAuthorisationNotification(string $incrementId, string $resultCode): VerificationResult
    {
        $notification = $this->getLatestNotification($incrementId, Notification::AUTHORISATION);

        if ($notification === null) {
            return new VerificationResult(
                VerificationResult::FAIL,
                sprintf(
                    'No AUTHORISATION webhook received (resultCode: %s) — resultCode alone is not proof of payment',
                    $resultCode ?: 'n/a'
                )
            );
        }

        if (!$notification->isSuccessful()) {
            return new VerificationResult(
                VerificationResult::FAIL,
                sprintf('AUTHORISATION webhook unsuccessful (resultCode: %s)', $resultCode ?: 'n/a')
            );
        }

        if (!$notification->getDone()) {
            return new VerificationResult(
                VerificationResult::DEFER,
                'Successful AUTHORISATION webhook not yet processed by Adyen cron — retry next run'
            );
        }

        return new VerificationResult(
            VerificationResult::SUCCESS_AUTHORISED,
            sprintf('Successful processed AUTHORISATION webhook (resultCode: %s)', $resultCode ?: 'n/a')
        );
    }

    private function hasSuccessfulNotification(string $incrementId, string $eventCode): bool
    {
        $notification = $this->getLatestNotification($incrementId, $eventCode);

        return $notification !== null && $notification->isSuccessful();
    }

    private function getLatestNotification(string $incrementId, string $eventCode): ?Notification
    {
        $collection = $this->notificationCollectionFactory->create()
            ->addFieldToFilter('merchant_reference', $incrementId)
            ->addFieldToFilter('event_code', $eventCode);
        $collection->getSelect()->order(['created_at DESC', 'entity_id DESC'])->limit(1);

        /** @var Notification|false $notification */
        $notification = $collection->getFirstItem();

        return $notification->getId() ? $notification : null;
    }
}
