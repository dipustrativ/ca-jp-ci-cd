<?php

declare(strict_types=1);

namespace CarpetAvenue\ReservationsFix\Plugin\Inventory;

use CarpetAvenue\ReservationsFix\Helper\Data;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\InventoryReservationsApi\Model\ReservationInterface;
use Psr\Log\LoggerInterface;

use CarpetAvenue\ReservationsFix\Api\ReservationsManagerInterface;

class AppendReservations
{
    protected Data $dataHelper;
    protected SerializerInterface $serializer;
    protected LoggerInterface $logger;
    protected ReservationsManagerInterface $reservationsManager;
    public function __construct(
        LoggerInterface $logger,
        Data $dataHelper,
        SerializerInterface $serializer,
        ReservationsManagerInterface $reservationsManager
    ) {
        $this->dataHelper = $dataHelper;
        $this->serializer = $serializer;
        $this->logger = $logger;
        $this->reservationsManager = $reservationsManager;
    }
    public function beforeExecute(\Magento\InventoryReservations\Model\AppendReservations $subject, array $reservations){
        if (!$this->dataHelper->isEnabled()) {
            return [$reservations];
        }
        return $this->fixReservationsForMultiStock($subject, $reservations);
    }
    protected function fixReservationsForMultiStock(\Magento\InventoryReservations\Model\AppendReservations $subject, array $reservations){
        $enabledStockId = $this->dataHelper->getEnabledStockId();
        $fromSource = $this->dataHelper->getFromSource();
        $toSource = $this->dataHelper->getToSource();

        /** @var ReservationInterface $reservation */
        foreach ($reservations as $reservation) {
            // on order create: Find all other stocks that have sku in mutual source and add reservations.
            // take into consideration the current saleable qty and deduct the qty from sources not in the originating stock.
            $metadata = $this->serializer->unserialize($reservation->getMetadata());
            $adjust = [];
            switch ($metadata['event_type']) {
                case 'order_placed':
                    if ($reservation->getStockId() === $enabledStockId){
                        $this->reservationsManager->storePossibleSourceMove($reservation, $toSource, $fromSource, $metadata['object_increment_id']);
                    }
                    $adjust = $this->reservationsManager->createOrderPlaceAdjustReservations($reservation, $metadata);
                    break;
                case 'order_canceled':
                    $adjust = $this->reservationsManager->createCancelOrderReservations($reservation, $metadata);
                    if ($reservation->getStockId() === $enabledStockId){
                        $this->reservationsManager->removeInventoryMoveForOrder($metadata['object_increment_id'],'Order canceled');
                    }
                    break;
                case 'order_place_failed':
                    $adjust = $this->reservationsManager->createCancelOrderReservations($reservation, $metadata);
                    if ($reservation->getStockId() === $enabledStockId){
                        $this->reservationsManager->removeInventoryMoveForOrder($metadata['object_increment_id'],'Order failed');
                    }
                    break;
                case 'shipment_created':
                    $adjust = $this->reservationsManager->createShipmentReservations($reservation, $metadata);
                    break;
                case 'creditmemo_created':
                    $adjust = $this->reservationsManager->createCreditmemoReservations($reservation, $metadata);
                    break;
            }
            if (count($adjust)){
                $reservations = array_merge($reservations,$adjust);
            }
        }
        return [$reservations];
    }
}
