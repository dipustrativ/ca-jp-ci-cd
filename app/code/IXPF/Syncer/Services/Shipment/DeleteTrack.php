<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Shipment;

use Exception;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Api\ShipmentTrackRepositoryInterface;
use Magento\Sales\Model\Order\Shipment;

/**
 * Deletes tracking number(s) from an existing shipment — same result as the
 * admin "Delete" action on a track row in the shipment view.
 */
class DeleteTrack
{
    public const EVENT_AFTER_DELETE_TRACK = 'ixpf_syncer_after_shipment_delete_track';

    public function __construct(
        protected readonly ShipmentRepositoryInterface      $shipmentRepository,
        protected readonly ShipmentTrackRepositoryInterface $trackRepository,
        protected readonly EventManager                     $eventManager,
    ) {
    }

    /**
     * A track is deleted when both track_number and carrier_code match a track on
     * the given shipment. Unmatched rows are reported under 'not_found';
     * 'success' is false when nothing was deleted.
     *
     * @param int   $shipmentId
     * @param array $payload {"tracks": [{"track_number": "...", "carrier_code": "..."}]}
     * @return array
     * @throws InputException
     * @throws NoSuchEntityException
     * @throws Exception
     */
    public function execute(int $shipmentId, array $payload): array
    {
        $rows = $this->normalizeTracks($payload['tracks'] ?? null);

        try {
            /** @var Shipment $shipment */
            $shipment = $this->shipmentRepository->get($shipmentId);
        } catch (NoSuchEntityException) {
            throw new NoSuchEntityException(__('Shipment #%1 not found', $shipmentId));
        }

        $remaining = [];
        $byKey = [];
        foreach ($shipment->getAllTracks() as $track) {
            $remaining[(int) $track->getEntityId()] = $track;
            $byKey[$this->key((string) $track->getTrackNumber(), (string) $track->getCarrierCode())][] = $track;
        }

        $toDelete = [];
        $notFound = [];
        foreach ($rows as $row) {
            $matches = $byKey[$this->key($row['track_number'], $row['carrier_code'])] ?? [];
            if (!$matches) {
                $notFound[] = $row;
                continue;
            }
            foreach ($matches as $track) {
                $toDelete[(int) $track->getEntityId()] = $track;
            }
        }

        $deleted = [];
        foreach ($toDelete as $id => $track) {
            $deleted[] = $this->trackRow($track);
            $this->trackRepository->delete($track);
            unset($remaining[$id]);
        }

        if ($deleted) {
            $this->eventManager->dispatch(self::EVENT_AFTER_DELETE_TRACK, [
                'shipment' => $shipment,
                'tracks'   => $deleted,
            ]);
        }

        return [
            'success'               => (bool) $deleted,
            'shipment_id'           => (int) $shipment->getEntityId(),
            'shipment_increment_id' => (string) $shipment->getIncrementId(),
            'order_id'              => (int) $shipment->getOrderId(),
            'deleted'               => $deleted,
            'not_found'             => $notFound,
            'tracks'                => array_map(fn($track) => $this->trackRow($track), array_values($remaining)),
        ];
    }

    /**
     * Validate payload tracks; duplicate rows are collapsed.
     *
     * @param mixed $rows
     * @return array<int, array{track_number: string, carrier_code: string}>
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
            $number = $row['track_number'] ?? $row['number'] ?? '';
            $number = is_scalar($number) ? trim((string) $number) : '';
            if ($number === '') {
                throw new InputException(__('tracks[%1].track_number is required', $i));
            }
            $carrierCode = $row['carrier_code'] ?? '';
            $carrierCode = is_scalar($carrierCode) ? trim((string) $carrierCode) : '';
            if ($carrierCode === '') {
                throw new InputException(__('tracks[%1].carrier_code is required', $i));
            }
            $tracks[$this->key($number, $carrierCode)] = [
                'track_number' => $number,
                'carrier_code' => $carrierCode,
            ];
        }
        return array_values($tracks);
    }

    /**
     * Match key: exact track number + case-insensitive carrier code.
     */
    private function key(string $number, string $carrierCode): string
    {
        return $number . "\0" . strtolower($carrierCode);
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
