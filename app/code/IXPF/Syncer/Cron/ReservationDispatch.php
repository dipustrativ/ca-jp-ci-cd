<?php

declare(strict_types=1);

namespace IXPF\Syncer\Cron;

use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\ReservationLogger;
use IXPF\Syncer\Model\IxpfReservation;
use IXPF\Syncer\Model\ResourceModel\IxpfReservation as ReservationResource;
use IXPF\Syncer\Services\Reservation\Sender;
use Throwable;

/**
 * Sends reservation batches still waiting to go out.
 *
 * This is not a redundancy behind the observers — it is the primary path wherever
 * no observer ran (CLI order creation), the backstop when the fire-and-forget
 * write could not open a connection, and what re-sends a batch IXPF never
 * acknowledged once ReservationRetry has released it back to `pending`.
 *
 * Unlike the observers' path this one blocks on the reply, so a batch it sends is
 * settled inline and does not need an acknowledgement to leave `processing`.
 */
class ReservationDispatch
{
    public function __construct(
        private readonly Config $config,
        private readonly ReservationResource $reservationResource,
        private readonly Sender $sender,
        private readonly ReservationLogger $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isReservationEnabled()) {
            return;
        }

        try {
            $batchIds = $this->reservationResource->getDispatchableBatchIds(
                [IxpfReservation::REQUEST_PENDING, IxpfReservation::REQUEST_PROCESSING],
                $this->config->getReservationMaxAttempts(),
                $this->config->getReservationDispatchLimit()
            );

            foreach ($batchIds as $batchId) {
                $this->sender->send($batchId);
            }

            if ($batchIds) {
                $this->logger->writeDaily(
                    sprintf('Reservation dispatch cron sent %d batch(es).', count($batchIds))
                );
            }
        } catch (Throwable $e) {
            $this->logger->writeExceptionDaily($e, 'Reservation dispatch cron exception');
        }
    }
}
