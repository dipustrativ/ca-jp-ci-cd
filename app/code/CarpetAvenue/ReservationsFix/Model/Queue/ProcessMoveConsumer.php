<?php

declare(strict_types=1);

namespace CarpetAvenue\ReservationsFix\Model\Queue;

use CarpetAvenue\ReservationsFix\Model\ResourceModel\InventoryMove\CollectionFactory;
use CarpetAvenue\ReservationsFix\Helper\Data;
use CarpetAvenue\ReservationsFix\Api\ReservationsManagerInterface;

class ProcessMoveConsumer
{
    protected CollectionFactory $collectionFactory;
    protected Data $dataHelper;
    protected ReservationsManagerInterface $reservationsManager;

    public function __construct(
        CollectionFactory $collectionFactory,
        Data $dataHelper,
        ReservationsManagerInterface $reservationsManager
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->dataHelper = $dataHelper;
        $this->reservationsManager = $reservationsManager;
    }

    public function execute(ProcessMove $processMove)
    {
        $autoFixAction = $this->dataHelper->getAutoFixAction();

        $inventoryMoveCollection = $this->collectionFactory->create();
        $inventoryMoveCollection->addFieldToFilter('increment_id', ['eq' => $processMove->getIncrementId()]);
        $inventoryMoveCollection->addFieldToFilter('processed', ['eq' => false]);
        foreach ($inventoryMoveCollection->getItems() as $inventoryMove) {
            if ('fix_reservation' == $autoFixAction) {
                $this->reservationsManager->moveInventoryAssociatedResevations($inventoryMove);
                $this->reservationsManager->setProcessed($inventoryMove,'Order status: fix reservation');
            }
            else if ('fix_reservation_destination' == $autoFixAction) {
                $this->reservationsManager->increaseDestination($inventoryMove);
                $this->reservationsManager->moveInventoryAssociatedResevations($inventoryMove);
                $this->reservationsManager->setProcessed($inventoryMove,'Order status: fix reservation, destination');
            }
            else if ('fix_reservation_move' == $autoFixAction) {
                $this->reservationsManager->moveInventory($inventoryMove);
                $this->reservationsManager->moveInventoryAssociatedResevations($inventoryMove);
                $this->reservationsManager->setProcessed($inventoryMove,'Order status: fix reservation, move');
            }
            else {
                $this->reservationsManager->setProcessed($inventoryMove,'Order status: no action');
            }
        }
    }
}
