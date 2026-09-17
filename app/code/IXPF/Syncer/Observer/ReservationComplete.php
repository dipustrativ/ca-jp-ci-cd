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
 * Tell IXPF a reservation has been consumed for good.
 *
 * Magento has no order state-change event — Order.php dispatches only
 * sales_order_place_before/_after and order_cancel_after — so the transition is
 * detected by comparing the saved state with getOrigData() on
 * sales_order_save_after.
 *
 * origData alone is not enough: an order saved twice in one request can present
 * the same transition again, so the table is also asked whether this order has
 * already reported `completed`.
 */
class ReservationComplete extends AbstractReservationObserver
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
        return IxpfReservation::RESERVATION_COMPLETED;
    }

    protected function resolve(Observer $observer): ?array
    {
        /** @var Order|null $order */
        $order = $observer->getEvent()->getData('order');
        if (!$order || !$order->getEntityId()) {
            return null;
        }

        if ($order->getState() !== Order::STATE_COMPLETE) {
            return null;
        }

        if ($order->getOrigData('state') === Order::STATE_COMPLETE) {
            return null;
        }

        $orderId = (int)$order->getEntityId();

        if ($this->reservationResource->hasStatus($orderId, IxpfReservation::RESERVATION_COMPLETED)) {
            return null;
        }

        // Falls back to resolving the source now when the order has no placement
        // batch, rather than refusing to tell IXPF the order moved on.
        $resolved = $this->replay->resolve($order);

        if (!$resolved['lines']) {
            $this->recorder->recordFailure(
                IxpfReservation::DIRECTION_OUTBOUND,
                IxpfReservation::RESERVATION_COMPLETED,
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
            $lines[] = ['sku' => (string)$item->getSku(), 'qty' => -$qty];
        }

        return $lines;
    }
}
