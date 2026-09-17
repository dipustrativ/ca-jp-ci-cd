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
use Magento\Sales\Model\Order;

/**
 * Release the IXPF hold when an order is cancelled.
 *
 * order_cancel_after (Order.php:1337) fires once per cancellation, so no origData
 * comparison is needed — only a guard against the same order being cancelled
 * twice, which Magento permits on a partially cancelled order.
 */
class ReservationCancel extends AbstractReservationObserver
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
        return IxpfReservation::RESERVATION_CANCELLED;
    }

    /**
     * Cancelling hands the stock back.
     */
    protected function returnsStock(): bool
    {
        return true;
    }

    protected function resolve(Observer $observer): ?array
    {
        /** @var Order|null $order */
        $order = $observer->getEvent()->getData('order');
        if (!$order || !$order->getEntityId()) {
            return null;
        }

        $orderId = (int)$order->getEntityId();

        if ($this->reservationResource->hasStatus($orderId, IxpfReservation::RESERVATION_CANCELLED)) {
            return null;
        }

        // Falls back to resolving the source now when the order has no placement
        // batch, rather than refusing to tell IXPF the order moved on.
        $resolved = $this->replay->resolve($order);

        if (!$resolved['lines']) {
            $this->recorder->recordFailure(
                IxpfReservation::DIRECTION_OUTBOUND,
                IxpfReservation::RESERVATION_CANCELLED,
                $orderId,
                $this->replay->describeMissing($orderId),
                null,
                $this->orderLines($order)
            );

            return null;
        }

        return [
            'order_id' => $orderId,
            'lines' => $resolved['lines'],
            'information' => $resolved['inferred'] ? $this->replay->inferredNote($orderId) : null,
        ];
    }

    /**
     * Ordered SKUs and quantities, with no source resolved.
     *
     * @return array<int, array{sku: string, qty: float}>
     */
    private function orderLines(Order $order): array
    {
        $lines = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $qty = (float)$item->getQtyOrdered();
            if ($qty <= 0) {
                continue;
            }
            $lines[] = ['sku' => (string)$item->getSku(), 'qty' => $qty];
        }

        return $lines;
    }
}
