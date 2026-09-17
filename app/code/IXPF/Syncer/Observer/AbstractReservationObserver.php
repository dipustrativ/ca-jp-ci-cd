<?php

declare(strict_types=1);

namespace IXPF\Syncer\Observer;

use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\ReservationLogger;
use IXPF\Syncer\Model\IxpfReservation;
use IXPF\Syncer\Services\Reservation\Recorder;
use IXPF\Syncer\Services\Reservation\Sender;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Throwable;

/**
 * Shared shape for the four reservation observers.
 *
 * Every subclass resolves an order id and a set of lines; this base writes the
 * batch and hands it to the sender's fire-and-forget path, which writes the
 * request and hangs up without reading the reply — so neither a customer placing
 * an order nor an admin shipping one ever waits on the ERP.
 *
 * Nothing here is allowed to throw. sales_model_service_quote_submit_success is
 * dispatched inside QuoteManagement::submitQuote's try block, so an escaping
 * exception routes to sales_model_service_quote_submit_failure and rolls the
 * order back; a cancel or refund must likewise never fail because the ERP is
 * unreachable.
 */
abstract class AbstractReservationObserver implements ObserverInterface
{
    public function __construct(
        protected readonly Config $config,
        protected readonly Recorder $recorder,
        protected readonly Sender $sender,
        protected readonly ReservationLogger $logger
    ) {
    }

    /**
     * Lifecycle stage this observer reports.
     */
    abstract protected function getReservationStatus(): string;

    /**
     * Resolve the batch to send, or null to stay silent.
     *
     * `information` is optional and records how the lines were arrived at — set
     * when the source had to be resolved at event time instead of replayed.
     *
     * @return array{order_id: int, lines: array<int, array{sku: string, qty: float, source: string}>, event_ref?: string|null, information?: string|null}|null
     */
    abstract protected function resolve(Observer $observer): ?array;

    public function execute(Observer $observer): void
    {
        try {
            if (!$this->config->isReservationEnabled()) {
                return;
            }

            $resolved = $this->resolve($observer);
            if ($resolved === null) {
                return;
            }

            $batchId = $this->recorder->record(
                IxpfReservation::DIRECTION_OUTBOUND,
                $this->getReservationStatus(),
                $resolved['order_id'],
                $this->signLines($resolved['lines']),
                $resolved['event_ref'] ?? null,
                IxpfReservation::REQUEST_PENDING,
                $resolved['information'] ?? null
            );

            $this->sender->sendAsync($batchId);
        } catch (Throwable $e) {
            $this->logger->writeExceptionDaily($e, static::class . ' reservation observer exception');
        }
    }

    /**
     * Apply the sign that matches this lifecycle stage.
     *
     * Placement and completion consume stock (negative); cancellation and refund
     * hand it back (positive).
     *
     * @param array<int, array{sku: string, qty: float, source: string}> $lines
     * @return array<int, array{sku: string, qty: float, source: string}>
     */
    protected function signLines(array $lines): array
    {
        $sign = $this->returnsStock() ? 1 : -1;

        foreach ($lines as &$line) {
            $line['qty'] = abs((float)$line['qty']) * $sign;
        }

        return $lines;
    }

    /**
     * Does this stage give stock back?
     */
    protected function returnsStock(): bool
    {
        return false;
    }
}
