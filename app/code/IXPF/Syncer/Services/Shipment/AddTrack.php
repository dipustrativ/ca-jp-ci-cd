<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Shipment;

use Exception;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\Shipping\Model\ShipmentNotifier;

/**
 * Adds one or more tracking numbers to an existing shipment — same result as the
 * admin "Add Tracking Number" action on the shipment view.
 */
class AddTrack
{
    public const EVENT_AFTER_ADD_TRACK = 'ixpf_syncer_after_shipment_add_track';

    public function __construct(
        protected readonly ShipmentRepositoryInterface $shipmentRepository,
        protected readonly TrackFactory                $trackFactory,
        protected readonly ShipmentNotifier            $shipmentNotifier,
        protected readonly EventManager                $eventManager,
    ) {
    }

    /**
     * @param int   $shipmentId
     * @param array $payload {"tracks": [{"track_number": "...", "title": "...", "carrier_code": "custom"}], "notify": bool}
     * @return array
     * @throws InputException
     * @throws NoSuchEntityException
     * @throws Exception
     */
    public function execute(int $shipmentId, array $payload): array
    {
        $rows = $this->normalizeTracks($payload['tracks'] ?? null);
        $notify = !empty($payload['notify']);

        try {
            /** @var Shipment $shipment */
            $shipment = $this->shipmentRepository->get($shipmentId);
        } catch (NoSuchEntityException) {
            throw new NoSuchEntityException(__('Shipment #%1 not found', $shipmentId));
        }

        $existing = [];
        foreach ($shipment->getAllTracks() as $track) {
            $existing[(string) $track->getTrackNumber()] = true;
        }

        $newTracks = [];
        $skipped = [];
        foreach ($rows as $row) {
            if (isset($existing[$row['number']])) {
                $skipped[] = $row['number'];
                continue;
            }
            $existing[$row['number']] = true;

            $track = $this->trackFactory->create();
            $track->setNumber($row['number'])
                ->setTitle($row['title'])
                ->setCarrierCode($row['carrier_code']);
            $shipment->addTrack($track);
            $newTracks[] = $track;
        }

        if ($newTracks) {
            $this->shipmentRepository->save($shipment);

            $this->eventManager->dispatch(self::EVENT_AFTER_ADD_TRACK, [
                'shipment' => $shipment,
                'tracks'   => $newTracks,
            ]);
        }

        $notified = false;
        if ($notify && $newTracks) {
            $notified = (bool) $this->shipmentNotifier->notify($shipment);
        }

        return [
            'success'               => true,
            'shipment_id'           => (int) $shipment->getEntityId(),
            'shipment_increment_id' => (string) $shipment->getIncrementId(),
            'order_id'              => (int) $shipment->getOrderId(),
            'added'                 => array_map(fn($track) => $this->trackRow($track), $newTracks),
            'skipped'               => $skipped,
            'tracks'                => array_map(fn($track) => $this->trackRow($track), array_values($shipment->getAllTracks())),
            'notified'              => $notified,
        ];
    }

    /**
     * Validate payload tracks and map to Track model fields.
     *
     * @param mixed $rows
     * @return array<int, array{number: string, title: string, carrier_code: string}>
     * @throws InputException
     */
    private function normalizeTracks(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            throw new InputException(__("'tracks' is required and must be a non-empty array"));
        }

        $tracks = [];
        foreach (array_values($rows) as $i => $row) {
            if (!is_array($row)) {
                throw new InputException(__('tracks[%1] must be an object', $i));
            }
            $number = trim((string) ($row['track_number'] ?? $row['number'] ?? ''));
            if ($number === '') {
                throw new InputException(__('tracks[%1].track_number is required', $i));
            }
            $carrierCode = trim((string) ($row['carrier_code'] ?? ''));
            $tracks[] = [
                'number'       => $number,
                'title'        => trim((string) ($row['title'] ?? '')),
                // Default 'custom' so Magento treats title/number as free text
                'carrier_code' => $carrierCode !== '' ? $carrierCode : 'custom',
            ];
        }
        return $tracks;
    }

    /**
     * @param Shipment\Track $track
     * @return array
     */
    private function trackRow(Shipment\Track $track): array
    {
        return [
            'track_id'     => (int) $track->getEntityId(),
            'track_number' => (string) $track->getTrackNumber(),
            'title'        => (string) $track->getTitle(),
            'carrier_code' => (string) $track->getCarrierCode(),
        ];
    }
}
