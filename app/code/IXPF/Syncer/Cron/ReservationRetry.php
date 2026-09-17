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
 * Re-sends reservation batches IXPF rejected or never answered.
 *
 * Also releases batches stuck in `processing`. Two things leave one there: a
 * worker killed mid-send, and a fire-and-forget send IXPF never acknowledged.
 * Without this release neither would ever be retried, since the dispatch cron
 * only claims `pending`.
 */
class ReservationRetry
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
            $recovered = $this->reservationResource->recoverStuckBatches();
            if ($recovered > 0) {
                $this->logger->writeDaily(
                    sprintf('Reservation retry cron released %d stuck row(s).', $recovered),
                    'warning'
                );
            }

            $batchIds = $this->reservationResource->getDispatchableBatchIds(
                [IxpfReservation::REQUEST_FAILED],
                $this->config->getReservationMaxAttempts(),
                $this->config->getReservationDispatchLimit()
            );

            foreach ($batchIds as $batchId) {
                $this->sender->send($batchId);
            }

            if ($batchIds) {
                $this->logger->writeDaily(
                    sprintf('Reservation retry cron re-sent %d batch(es).', count($batchIds))
                );
            }
        } catch (Throwable $e) {
            $this->logger->writeExceptionDaily($e, 'Reservation retry cron exception');
        }
    }
}
