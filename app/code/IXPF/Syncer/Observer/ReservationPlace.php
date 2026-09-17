<?php

declare(strict_types=1);

namespace IXPF\Syncer\Observer;

use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\ReservationLogger;
use IXPF\Syncer\Model\IxpfReservation;
use IXPF\Syncer\Services\Reservation\Collector;
use IXPF\Syncer\Services\Reservation\Recorder;
use IXPF\Syncer\Services\Reservation\Sender;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Throwable;

/**
 * Reserve stock in IXPF the moment an order is placed.
 *
 * Hooked to sales_model_service_quote_submit_success, which fires once per order
 * inside QuoteManagement::submitQuote right after orderManagement->place() — so
 * the order is saved and has an entity_id, and storefront checkout, one-step
 * checkout, admin order create and the IXPF Quote/Submit API all pass through it.
 * sales_order_save_after would have fired again on every later status change.
 *
 * This is the only observer that runs source selection. Every later stage
 * replays what is recorded here.
 */
class ReservationPlace extends AbstractReservationObserver
{
    public function __construct(
        Config $config,
        Recorder $recorder,
        Sender $sender,
        ReservationLogger $logger,
        private readonly Collector $collector
    ) {
        parent::__construct($config, $recorder, $sender, $logger);
    }

    protected function getReservationStatus(): string
    {
        return IxpfReservation::RESERVATION_PENDING;
    }

    protected function resolve(Observer $observer): ?array
    {
        /** @var Order|null $order */
        $order = $observer->getEvent()->getData('order');
        if (!$order || !$order->getEntityId()) {
            return null;
        }

        $orderId = (int)$order->getEntityId();

        try {
            $lines = $this->collector->collect($order);
        } catch (Throwable $e) {
            // Record the gap instead of dropping it — an order we never reserved
            // has to be visible in the grid, and guessing a source would be worse.
            $this->recorder->recordFailure(
                IxpfReservation::DIRECTION_OUTBOUND,
                IxpfReservation::RESERVATION_PENDING,
                $orderId,
                $e->getMessage(),
                null,
                // The SKUs and quantities are known; only the warehouse was not.
                $this->orderLines($order)
            );
            $this->logger->writeExceptionDaily(
                $e,
                sprintf('Reservation source selection failed for order %d', $orderId)
            );

            return null;
        }

        if (!$lines) {
            return null;
        }

        return ['order_id' => $orderId, 'lines' => $lines];
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
