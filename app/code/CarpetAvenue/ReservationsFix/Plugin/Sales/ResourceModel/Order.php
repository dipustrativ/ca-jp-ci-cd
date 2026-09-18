<?php

declare(strict_types=1);

namespace CarpetAvenue\ReservationsFix\Plugin\Sales\ResourceModel;

use Magento\Framework\MessageQueue\PublisherInterface;
use CarpetAvenue\ReservationsFix\Helper\Data;
use CarpetAvenue\ReservationsFix\Model\Queue\ProcessMoveFactory;

class Order{
    //protected \CarpetAvenue\ReservationsFix\Model\ResourceModel\InventoryMove\CollectionFactory $collectionFactory;
    protected Data $dataHelper;
    protected PublisherInterface $publisher;
    protected ProcessMoveFactory $processMoveFactory;
    //protected \CarpetAvenue\ReservationsFix\Model\ResourceModel\InventoryMove $inventoryMoveResource;
    //protected \CarpetAvenue\ReservationsFix\Model\StockMoveConsumer $stockMoveConsumer;
    //protected \CarpetAvenue\ReservationsFix\Model\MoveDataFactory $moveDataFactory;
    //protected \CarpetAvenue\ReservationsFix\Api\ReservationsManagerInterface $reservationsManager;
    public function __construct(
        //\CarpetAvenue\ReservationsFix\Model\ResourceModel\InventoryMove\CollectionFactory $collectionFactory,
        //\CarpetAvenue\ReservationsFix\Model\ResourceModel\InventoryMove $inventoryMoveResource,
        Data $dataHelper,
        PublisherInterface $publisher,
        ProcessMoveFactory $processMoveFactory
        //\CarpetAvenue\ReservationsFix\Model\StockMoveConsumer $stockMoveConsumer,
        //\CarpetAvenue\ReservationsFix\Model\MoveDataFactory $moveDataFactory,
        //\CarpetAvenue\ReservationsFix\Api\ReservationsManagerInterface $reservationsManager
    ){
        //$this->collectionFactory = $collectionFactory;
        //$this->inventoryMoveResource = $inventoryMoveResource;
        $this->dataHelper = $dataHelper;
        $this->publisher = $publisher;
        $this->processMoveFactory = $processMoveFactory;
        //$this->stockMoveConsumer = $stockMoveConsumer;
        //$this->moveDataFactory = $moveDataFactory;
        //$this->reservationsManager = $reservationsManager;
    }
    public function afterSave(\Magento\Sales\Model\ResourceModel\Order $subject, $result, $order){
        if (!$this->dataHelper->isEnabled()) {
            return $result;
        }
        if (!$this->dataHelper->isAutoFixEnabled()) {
            return $result;
        }
        if ($this->dataHelper->getAutoFixOrderStatus() !== $order->getStatus()){
            return $result;
        }

        $processMove = $this->processMoveFactory->create(['incrementid' => $order->getIncrementId()]);
        $this->publisher->publish('carpet_reserations_fix.process_move', $processMove);

        /*$autoFixAction = $this->dataHelper->getAutoFixAction();

        $inventoryMoveCollection = $this->collectionFactory->create();
        $inventoryMoveCollection->addFieldToFilter('increment_id', ['eq' => $order->getIncrementId()]);
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
        }*/
        return $result;
    }
}