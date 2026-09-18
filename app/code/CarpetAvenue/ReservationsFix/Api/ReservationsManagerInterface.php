<?php

namespace CarpetAvenue\ReservationsFix\Api;

use Magento\InventoryReservationsApi\Model\ReservationInterface;
use CarpetAvenue\ReservationsFix\Model\InventoryMove;

/**
 * Interface for reservations
 *
 * @api
 */
interface ReservationsManagerInterface{

    /**
     * @param ReservationInterface $reservation
     * @param string $toSource
     * @param string $fromSource
     * @param string $incrementId
     * @return void
     */
     public function storePossibleSourceMove(ReservationInterface $reservation, string $toSource, string $fromSource, $incrementId): void;

    /**
     * @param InventoryMove $move
     * @param string $reason
     */
     public function setProcessed(InventoryMove $move, $reason);

     /**
     * @param InventoryMove $move
     * @return bool
     */
     public function increaseDestination(InventoryMove $move): bool;

     /**
     * @param InventoryMove $move
     * @return bool
     */
    public function moveInventory(InventoryMove $move): bool;

    /**
     * @param InventoryMove $move
     * @return bool
     */
    public function moveInventoryAssociatedResevations(InventoryMove $move): bool;

    /**
     * @param string $incrementId
     * @param string $reason
     */
    public function removeInventoryMoveForOrder($incrementId, $reason);

    /**
     * @param InventoryMove $move
     * @param string $reason
     * @return bool
     */
    public function removeInventoryMove(InventoryMove $move, $reason): bool;

    /**
     * @param ReservationInterface $reservation
     * @param array $metadata
     * @return array|null
     */
    public function createOrderPlaceAdjustReservations(ReservationInterface $reservation, $metadata);
    
    /**
     * @param ReservationInterface $reservation
     * @param array $metadata
     * @return array|null
     */
    public function createCancelOrderReservations(ReservationInterface $reservation, $metadata);

    /**
     * @param ReservationInterface $reservation
     * @param array $metadata
     * @return array|null
     */
    public function createShipmentReservations(ReservationInterface $reservation, $metadata);

    /**
     * @param ReservationInterface $reservation
     * @param array $metadata
     * @return array|null
     */
    public function createCreditmemoReservations(ReservationInterface $reservation, $metadata);

    /**
     * @param string $incrementId
     * @param string $type
     * @return array|null
     */
    public function getReservationsForOrder($incrementId, $type);

    /**
     * @return array|null
     */
    public function getOrdersWithReservations();
}