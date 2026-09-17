<?php

declare(strict_types=1);

namespace IXPF\Syncer\Observer;

use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\ReservationLogger;
use IXPF\Syncer\Model\IxpfReservation;
use IXPF\Syncer\Model\ResourceModel\IxpfReservation as ReservationResource;
use IXPF\Syncer\Services\Reservation\Recorder;
use IXPF\Syncer\Services\Reservation\Replay;
use IXPF\Syncer\Services\Reservation\Sender;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order\Creditmemo;

/**
 * Report a refund against the reservation.
 *
 * A credit memo carries quantities but no source, so the credited amounts are
 * mapped back onto the placement rows rather than re-selected — see Replay.
 *
 * Unlike completion and cancellation, several partial refunds against one order
 * are legitimate, so the duplicate guard keys on the credit memo id rather than
 * on the order.
 */
class ReservationRefund extends AbstractReservationObserver
{
    public function __construct(
        Config $config,
        Recorder $recorder,
        Sender $sender,
        ReservationLogger $logger,
        private readonly Replay $replay,
        private readonly ReservationResource $reservationResource
    ) {
        parent::__construct($config, $recorder, $sender, $logger);
    }

    protected function getReservationStatus(): string
    {
        return IxpfReservation::RESERVATION_REFUNDED;
    }

    /**
     * Refunding hands the stock back.
     */
    protected function returnsStock(): bool
    {
        return true;
    }

    protected function resolve(Observer $observer): ?array
    {
        /** @var Creditmemo|null $creditmemo */
        $creditmemo = $observer->getEvent()->getData('creditmemo');
        if (!$creditmemo || !$creditmemo->getEntityId()) {
            return null;
        }

        $order = $creditmemo->getOrder();
        if (!$order || !$order->getEntityId()) {
            return null;
        }

        $orderId = (int)$order->getEntityId();
        $eventRef = (string)$creditmemo->getEntityId();

        if ($this->reservationResource->hasStatus(
            $orderId,
            IxpfReservation::RESERVATION_REFUNDED,
            $eventRef
        )) {
            return null;
        }

        $skuQty = [];
        foreach ($creditmemo->getAllItems() as $item) {
            $qty = (float)$item->getQty();
            if ($qty <= 0) {
                continue;
            }
            $sku = (string)$item->getSku();
            $skuQty[$sku] = ($skuQty[$sku] ?? 0.0) + $qty;
        }

        if (!$skuQty) {
            return null;
        }

        $resolved = $this->replay->resolve($order, $skuQty);

        if (!$resolved['lines']) {
            $this->recorder->recordFailure(
                IxpfReservation::DIRECTION_OUTBOUND,
                IxpfReservation::RESERVATION_REFUNDED,
                $orderId,
                $this->replay->describeMissing($orderId),
                $eventRef,
                array_map(
                    static fn (string $sku, float $qty): array => ['sku' => $sku, 'qty' => $qty],
                    array_keys($skuQty),
                    array_values($skuQty)
                )
            );

            return null;
        }

        return [
            'order_id' => $orderId,
            'lines' => $resolved['lines'],
            'event_ref' => $eventRef,
            'information' => $resolved['inferred'] ? $this->replay->inferredNote($orderId) : null,
        ];
    }
}
