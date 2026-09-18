<?php

namespace CarpetAvenue\ReservationsFix\Model;

use Psr\Log\LoggerInterface;
use Magento\InventoryReservationsApi\Model\ReservationInterface;
use Magento\InventorySourceDeductionApi\Model\GetSourceItemBySourceCodeAndSku;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventoryReservationsApi\Model\GetReservationsQuantityInterface;
use CarpetAvenue\ReservationsFix\Model\InventoryMoveFactory;
use CarpetAvenue\ReservationsFix\Model\ResourceModel\InventoryMove as InventoryMoveResource;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\InventoryApi\Api\GetStockSourceLinksInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\InventoryReservationsApi\Model\ReservationBuilderInterface;
use Magento\Framework\Serialize\SerializerInterface;
use CarpetAvenue\ReservationsFix\Model\Session;
use CarpetAvenue\ReservationsFix\Model\InventoryMove;
use Magento\InventoryCatalogApi\Api\BulkSourceAssignInterface;
use Magento\InventoryCatalogApi\Api\Data\PartialInventoryTransferItemInterfaceFactory;
use Magento\InventoryCatalogApi\Api\BulkPartialInventoryTransferInterface;
use CarpetAvenue\ReservationsFix\Model\ResourceModel\InventoryMove\CollectionFactory as InventoryCollectionFactory;
use Magento\InventoryReservations\Model\ResourceModel\SaveMultiple as SaveMultipleReservations;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\Catalog\Model\Product\Action as ProductAction;
use CarpetAvenue\ReservationsFix\Helper\Data as FixDataHelper;
use Magento\InventoryApi\Api\StockRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;

class ReservationsManager implements \CarpetAvenue\ReservationsFix\Api\ReservationsManagerInterface{
    protected LoggerInterface $logger;
    protected GetSourceItemBySourceCodeAndSku $getSourceItemBySourceCodeAndSku;
    protected GetReservationsQuantityInterface $reservationsQuantity;
    protected InventoryMoveFactory $inventoryMoveFactory;
    protected InventoryMoveResource $inventoryMoveResource;
    protected GetSourceItemsBySkuInterface $getSourceItemsBySku;
    protected FilterBuilder $filterBuilder;
    protected SearchCriteriaBuilder $searchCriteriaBuilder;
    protected GetStockSourceLinksInterface $getStockSourceLinks;
    protected ResourceConnection $resource;
    protected ReservationBuilderInterface $reservationBuilder;
    protected SerializerInterface $serializer;
    protected Session $session;
    protected BulkSourceAssignInterface $bulkSourceAssign;
    protected PartialInventoryTransferItemInterfaceFactory $inventoryTransferItemInterfaceFactory;
    protected BulkPartialInventoryTransferInterface $inventoryTransfer;
    protected InventoryCollectionFactory $inventoryCollectionFactory;
    protected SaveMultipleReservations $saveMultipleReservations;
    protected SourceItemsSaveInterface $sourceItemsSave;
    protected ProductAction $productAction;
    protected FixDataHelper $dataHelper;
    protected StockRepositoryInterface $stockRepository;
    protected ProductRepositoryInterface $productRepository;
    protected WebsiteRepositoryInterface $websiteRepository;
    protected \Magento\Sales\Model\Spi\OrderResourceInterface $orderResource;
    protected \Magento\Sales\Api\Data\OrderInterfaceFactory $orderFactory;

    private $productCache = [];

    public function __construct(
        LoggerInterface $logger,
        GetSourceItemBySourceCodeAndSku $getSourceItemBySourceCodeAndSku,
        GetReservationsQuantityInterface $reservationsQuantity,
        InventoryMoveFactory $inventoryMoveFactory,
        InventoryMoveResource $inventoryMoveResource,
        GetSourceItemsBySkuInterface $getSourceItemsBySku,
        FilterBuilder $filterBuilder,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        GetStockSourceLinksInterface $getStockSourceLinks,
        ResourceConnection $resource,
        ReservationBuilderInterface $reservationBuilder,
        SerializerInterface $serializer,
        Session $session,
        BulkSourceAssignInterface $bulkSourceAssign,
        PartialInventoryTransferItemInterfaceFactory $inventoryTransferItemInterfaceFactory,
        BulkPartialInventoryTransferInterface $inventoryTransfer,
        InventoryCollectionFactory $inventoryCollectionFactory,
        SaveMultipleReservations $saveMultipleReservations,
        SourceItemsSaveInterface $sourceItemsSave,
        ProductAction $productAction,
        FixDataHelper $dataHelper,
        StockRepositoryInterface $stockRepository,
        ProductRepositoryInterface $productRepository,
        WebsiteRepositoryInterface $websiteRepository,
        \Magento\Sales\Model\Spi\OrderResourceInterface $orderResource,
        \Magento\Sales\Api\Data\OrderInterfaceFactory $orderFactory
    ){
        $this->logger = $logger;
        $this->getSourceItemBySourceCodeAndSku = $getSourceItemBySourceCodeAndSku;
        $this->reservationsQuantity = $reservationsQuantity;
        $this->inventoryMoveFactory = $inventoryMoveFactory;
        $this->inventoryMoveResource = $inventoryMoveResource;
        $this->getSourceItemsBySku = $getSourceItemsBySku;
        $this->filterBuilder = $filterBuilder;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->getStockSourceLinks = $getStockSourceLinks;
        $this->resource = $resource;
        $this->reservationBuilder = $reservationBuilder;
        $this->serializer = $serializer;
        $this->session = $session;
        $this->bulkSourceAssign = $bulkSourceAssign;
        $this->inventoryTransferItemInterfaceFactory = $inventoryTransferItemInterfaceFactory;
        $this->inventoryTransfer = $inventoryTransfer;
        $this->inventoryCollectionFactory = $inventoryCollectionFactory;
        $this->saveMultipleReservations = $saveMultipleReservations;
        $this->sourceItemsSave = $sourceItemsSave;
        $this->productAction = $productAction;
        $this->dataHelper = $dataHelper;
        $this->stockRepository = $stockRepository;
        $this->productRepository = $productRepository;
        $this->websiteRepository = $websiteRepository;
        $this->orderResource = $orderResource;
        $this->orderFactory = $orderFactory;
    }

    public function fixMissingObjectId(InventoryMove $move){
        if ($move->getProcessed()){
            return true;
        }
        $order = $this->orderFactory->create();
        $this->orderResource->load($order, $move->getIncrementId(), \Magento\Sales\Api\Data\OrderInterface::INCREMENT_ID);
        $move->setObjectId($order->getEntityId());
        $move->setProcessStatus('');
        $move->setProcessed(true);
        $this->inventoryMoveResource->save($move);
        return true;
    }
    public function storePossibleSourceMove(ReservationInterface $reservation, string $toSource, string $fromSource, $incrementId): void{
        try {
            try {
                $sourceItemTo = $this->getSourceItemBySourceCodeAndSku->execute($toSource, $reservation->getSku());
                $sourceItemToQty = $sourceItemTo->getQuantity();
            } catch (NoSuchEntityException $e) {
                $sourceItemToQty = 0;
            }
            $reserveQty = abs($reservation->getQuantity());
            //$sourceItemFrom = $this->getSourceItemBySourceCodeAndSku->execute($fromSource, $reservation->getSku());
            if ($this->dataHelper->getCalculatePreciseQtyToMove()){
                $qtyReserved = $this->reservationsQuantity->execute($reservation->getSku(), $reservation->getStockId());
                $unreservedInDestination = $sourceItemToQty + $qtyReserved;
                if ($unreservedInDestination <= 0){
                    $qtyToMove = $reserveQty;
                }
                else {
                    $qtyToMove = $reserveQty - $unreservedInDestination;
                }
            }
            else{
                $qtyToMove = $reserveQty;
            }

            // Checks if a movement is needed. Checks qty physically in stock as well as reservations
            if ($qtyToMove > 0) {

                $allSourceCodes = $this->getSourcesCodesForSku($reservation->getSku());
                if (0 === count($allSourceCodes)){
                    return;
                }
                $primarySourceCodes = $this->getSourceCodesForSkuInStock($reservation->getStockId(), $allSourceCodes);
                $primaryUniqueSourceCodes = array_diff($primarySourceCodes, [$toSource]);

                $sourceItems = [];
                foreach ($primaryUniqueSourceCodes as $sourceCode){
                    $sourceItem = $this->getSourceForSku($reservation->getSku(),$sourceCode);
                    if ($sourceItem->getQuantity() > 0.000001){
                        if ($sourceItem->getQuantity() >= $qtyToMove){
                            $sourceItem->setQuantity($qtyToMove);
                            $sourceItems[] = $sourceItem;
                            break;
                        }
                        else{
                            $qtyToMove = $qtyToMove - $sourceItem->getQuantity();
                            $sourceItems[] = $sourceItem;
                        }
                    }
                }

                $metadata = $this->serializer->unserialize($reservation->getMetadata());
                //$qtyToMove = $reserveQty - ($sourceItemToQty + $qtyReserved);
                foreach($sourceItems as $sourceItem){
                    $inventoryMove = $this->inventoryMoveFactory->create();
                    $inventoryMove->setSku($reservation->getSku());
                    $inventoryMove->setSourceFrom($sourceItem->getSourceCode());
                    $inventoryMove->setSourceTo($toSource);
                    $inventoryMove->setQuantity($sourceItem->getQuantity());
                    $inventoryMove->setIncrementId($incrementId);
                    $inventoryMove->setStockId($reservation->getStockId());
                    $inventoryMove->setObjectId($metadata['object_id']);
                    $this->inventoryMoveResource->save($inventoryMove);
                }
            }
        }
        catch (\Exception $e) {
            $this->logger->error('RESERVATIONS FIX ERROR: Unable to save stock movement.');
            $this->logger->error($e->getMessage());
        }
    }
    public function setProcessed(InventoryMove $move, $reason){
        $move->setProcessStatus($reason);
        $move->setProcessed(true);
        $this->inventoryMoveResource->save($move);
    }
    public function increaseDestination(InventoryMove $move): bool{
        try {
            $sourceItem = $this->getSourceForSku($move->getSku(),$move->getSourceTo());
            if (!$sourceItem){
                $this->bulkSourceAssign->execute([$move->getSku()],[$move->getSourceTo()]);
            }
            $sourceItem = $this->getSourceForSku($move->getSku(),$move->getSourceTo());
            $sourceItem->setQuantity($sourceItem->getQuantity() + $move->getQuantity());
            $sourceItem->setStatus(\Magento\InventoryApi\Api\Data\SourceItemInterface::STATUS_IN_STOCK);
            $this->sourceItemsSave->execute([$sourceItem]);
            $this->logger->info(sprintf("RESERVATIONS FIX INFO: Added %s of %s to %s",
                $move->getQuantity(),
                $move->getSku(),
                $move->getSourceTo()
            ));

            if ($this->dataHelper->isWebsiteRemoveEnabled() && $this->productIsUnique($move->getSku())){
                if ($product = $this->getProduct($move->getSku())){
                    $stockData = $this->stockRepository->get($move->getStockId());
                    $salesChannels = $stockData->getExtensionAttributes()->getSalesChannels();
                    $websitesToAssign = [];
                    foreach ($salesChannels as $salesChannel){
                        if ($website = $this->websiteRepository->get($salesChannel->getCode())){
                            $websitesToAssign[] = $website->getId();
                        }
                    }
                    $websitesToRemove = array_diff($product->getWebsiteIds(), $websitesToAssign);
                    $this->productAction->updateWebsites([$product->getId()],$websitesToRemove, 'remove');
                    $this->productAction->updateWebsites([$product->getId()],$websitesToAssign, 'add');
                    $this->logger->info(sprintf("RESERVATIONS FIX INFO: Added %s to websites %s, removed from %s",
                        $move->getSku(),
                        join(',', $websitesToAssign),
                        join(',',$websitesToRemove)
                    ));
                }
            }
        }
        catch (\Exception $e) {
            $this->logger->critical(sprintf("RESERVATIONS FIX ERROR: Unable to increase stock for item %s", $move->getId()));
            $this->logger->critical($e->getMessage());
            return false;
        }
        return true;
    }
    public function moveInventory(InventoryMove $move): bool{
        try {
            if (!$this->hasSourceAssigned($move->getSku(),$move->getSourceTo())){
                $this->bulkSourceAssign->execute([$move->getSku()],[$move->getSourceTo()]);
            }
            $fromSourceItem = $this->getSourceForSku($move->getSku(),$move->getSourceFrom());
            if ($fromSourceItem->getQuantity() < $move->getQuantity()){
                $moveQty = $fromSourceItem->getQuantity();
            }
            else{
                $moveQty = $move->getQuantity();
            }
            $item = $this->inventoryTransferItemInterfaceFactory->create();
            $item->setSku($move->getSku());
            $item->setQty($moveQty);
            $this->inventoryTransfer->execute($move->getSourceFrom(), $move->getSourceTo(), [$item]);
            if ($sourceItem = $this->getSourceForSku($move->getSku(),$move->getSourceTo())){
                if (\Magento\InventoryApi\Api\Data\SourceItemInterface::STATUS_IN_STOCK != $sourceItem->getStatus()){
                    $sourceItem->setStatus(\Magento\InventoryApi\Api\Data\SourceItemInterface::STATUS_IN_STOCK);
                    $this->sourceItemsSave->execute([$sourceItem]);
                }
            }
            $this->logger->info(sprintf("RESERVATIONS FIX INFO: Moved %s of %s from %s to %s",
                $move->getQuantity(),
                $move->getSku(),
                $move->getSourceFrom(),
                $move->getSourceTo()
            ));
            if ($this->dataHelper->isWebsiteRemoveEnabled() && $this->productIsUnique($move->getSku())){
                if ($product = $this->getProduct($move->getSku())){
                    $stockData = $this->stockRepository->get($move->getStockId());
                    $salesChannels = $stockData->getExtensionAttributes()->getSalesChannels();
                    $websitesToAssign = [];
                    foreach ($salesChannels as $salesChannel){
                        if ($website = $this->websiteRepository->get($salesChannel->getCode())){
                            $websitesToAssign[] = $website->getId();
                        }
                    }
                    $websitesToRemove = array_diff($product->getWebsiteIds(), $websitesToAssign);
                    $this->productAction->updateWebsites([$product->getId()],$websitesToRemove, 'remove');
                    $this->productAction->updateWebsites([$product->getId()],$websitesToAssign, 'add');
                    $this->logger->info(sprintf("RESERVATIONS FIX INFO: Added %s to websites %s, removed from %s",
                        $move->getSku(),
                        join(',', $websitesToAssign),
                        join(',',$websitesToRemove)
                    ));
                }
            }

        }
        catch (\Exception $e) {
            $this->logger->critical(sprintf("RESERVATIONS FIX ERROR: Unable to move stock for item %s", $move->getId()));
            $this->logger->critical($e->getMessage());
            return false;
        }
        return true;
    }
    public function moveInventoryAssociatedResevations(InventoryMove $move): bool{
        try{
            $stocksToAdjust = $this->getStocksWithSources($move->getStockId(),[$move->getSourceFrom()]);
            $adjustingReservations = [];
            $customReservations = $this->getOrderCustomReservationsInStocksSumGroupByStockId($move->getSku(),$move->getIncrementId(),$stocksToAdjust);
            foreach ($customReservations as $customReservation){
                $newReservationQuantity = $customReservation['quantity'] + $move->getQuantity();
                if (0>=$newReservationQuantity){
                    $newReservationQuantity = $move->getQuantity();
                }
                else {//if (0<$newReservationQuantity){
                    $newReservationQuantity = $customReservation['quantity']*(-1);
                }
                $adjustingReservations[] = $this->reservationBuilder
                    ->setSku($move->getSku())
                    ->setQuantity((float)$newReservationQuantity)
                    ->setStockId((int)$customReservation['stock_id'])
                    ->setMetadata(
                        $this->serializer->serialize(
                            [
                                'event_type' => 'reservation_fix',
                                'object_type' => 'ca_order_adjust',
                                'object_id' => $move->getObjectId(),
                                'object_increment_id' => $move->getIncrementId()
                            ]
                        )
                    )
                    ->build();
            }
            $this->saveMultipleReservations->execute($adjustingReservations);
        }
        catch (\Exception $e) {
            $this->logger->critical(sprintf("RESERVATIONS FIX ERROR: Unable to process associated reservations for item %s", $move->getId()));
            $this->logger->critical($e->getMessage());
            return false;
        }

        return true;
    }
    public function removeInventoryMove(InventoryMove $move, $reason): bool{
        if ($move->getProcessed()){
            return true;
        }
        $move->setProcessStatus($reason);
        $move->setProcessed(true);
        $this->inventoryMoveResource->save($move);
        return true;
    }
    public function createOrderPlaceAdjustReservations(ReservationInterface $reservation, $metadata){
        try{
            $allSourceCodes = $this->getSourcesCodesForSku($reservation->getSku());
            if (0 === count($allSourceCodes)){
                return null;
            }
            $primarySourceCodes = $this->getSourceCodesForSkuInStock($reservation->getStockId(), $allSourceCodes);

            $stocksToAdjust = $this->getStocksWithSources($reservation->getStockId(), $primarySourceCodes);

            $adjustingReservations = [];
            foreach ($stocksToAdjust as $stockId){
                if ($this->dataHelper->getCalculatePreciseQtyToReserve()){
                    $targetSourceCodes = $this->getSourceCodesForSkuInStock($stockId, $allSourceCodes);
                    $primaryUniqueSourceCodes = array_diff($primarySourceCodes, $targetSourceCodes);
                    $primaryUniqueSourcesQty = 0;
                    $qtyReserved = 0;
                    if (0 < count($primaryUniqueSourceCodes)){
                        $qtyReserved = $this->getNonCustomReservationsForStock($reservation->getSku(), $reservation->getStockId());
                        foreach ($primaryUniqueSourceCodes as $sourceCode){
                            $sourceItem = $this->getSourceItemBySourceCodeAndSku->execute($sourceCode, $reservation->getSku());
                            $primaryUniqueSourcesQty += $sourceItem->getQuantity();
                        }
                    }
                    $qtyLeft = $primaryUniqueSourcesQty + $qtyReserved;
                    if ($qtyLeft > 0){
                        $adjustQty = $qtyLeft - abs($reservation->getQuantity());
                    }
                    else{
                        $adjustQty = $reservation->getQuantity();
                    }
                    if ($qtyLeft < abs($reservation->getQuantity())){
                        $adjustingReservations[] = $this->reservationBuilder
                            ->setSku($reservation->getSku())
                            ->setQuantity($adjustQty)
                            ->setStockId($stockId)
                            ->setMetadata(
                                $this->serializer->serialize(
                                    [
                                        'event_type' => $metadata['event_type'],
                                        'object_type' => 'ca_order_adjust',
                                        'object_id' => $metadata['object_id'],
                                        'object_increment_id' => $metadata['object_increment_id']
                                    ]
                                )
                            )
                            ->build();
                    }
                }
                else{
                    $adjustingReservations[] = $this->reservationBuilder
                        ->setSku($reservation->getSku())
                        ->setQuantity($reservation->getQuantity())
                        ->setStockId($stockId)
                        ->setMetadata(
                            $this->serializer->serialize(
                                [
                                    'event_type' => $metadata['event_type'],
                                    'object_type' => 'ca_order_adjust',
                                    'object_id' => $metadata['object_id'],
                                    'object_increment_id' => $metadata['object_increment_id']
                                ]
                            )
                        )
                        ->build();
                }
            }
            return $adjustingReservations;
        }
        catch (\Exception $e) {
            $this->logger->critical(sprintf("RESERVATIONS FIX ERROR: Unable to create Order place adjust reservations for item %s", $reservation->getReservationId()));
            $this->logger->critical($e->getMessage());
            return [];
        }

    }
    public function createCancelOrderReservations(ReservationInterface $reservation, $metadata){
        try{
            $adjustingReservations = [];
            $customReservations = $this->getOrderCustomReservations($reservation->getSku(), $metadata['object_increment_id']);
            foreach ($customReservations as $customReservation){
                $adjustingReservations[] = $this->reservationBuilder
                        ->setSku($reservation->getSku())
                        ->setQuantity((float)$customReservation['quantity']*(-1))
                        ->setStockId((int)$customReservation['stock_id'])
                        ->setMetadata(
                            $this->serializer->serialize(
                                [
                                    'event_type' => $metadata['event_type'],
                                    'object_type' => 'ca_order_adjust',
                                    'object_id' => $metadata['object_id'],
                                    'object_increment_id' => $metadata['object_increment_id']
                                ]
                            )
                        )
                        ->build();
            }
            return $adjustingReservations;
        }
        catch (\Exception $e) {
            $this->logger->critical(sprintf("RESERVATIONS FIX ERROR: Unable to create Order cancel reservations for item %s", $reservation->getReservationId()));
            $this->logger->critical($e->getMessage());
            return [];
        }

    }
    public function createShipmentReservations(ReservationInterface $reservation, $metadata){
        try{
            $adjustingReservations = [];
            // Is this a bit unnecessary?
            $source = $this->getCurrentShipmentSource();
            $stocksWithSource = $this->getStocksWithSources($reservation->getStockId(),[$source]);
            if (0===count($stocksWithSource)){
                return $adjustingReservations;
            }
            $customReservations = $this->getOrderCustomReservationsInStocksSumGroupByStockId($reservation->getSku(), $metadata['object_increment_id'], $stocksWithSource);
            foreach ($customReservations as $customReservation){
                if (0 == $customReservation['quantity'] ){
                    continue;
                }
                $newReservationQuantity = $customReservation['quantity'] - $reservation->getQuantity();
                if (0>=$newReservationQuantity){
                    $newReservationQuantity = $reservation->getQuantity();
                }
                else {//if (0<$newReservationQuantity){
                    $newReservationQuantity = $customReservation['quantity'];
                }

                $adjustingReservations[] = $this->reservationBuilder
                    ->setSku($reservation->getSku())
                    ->setQuantity((float)$newReservationQuantity)
                    ->setStockId((int)$customReservation['stock_id'])
                    ->setMetadata(
                        $this->serializer->serialize(
                            [
                                'event_type' => $metadata['event_type'],
                                'object_type' => 'ca_order_adjust',
                                'object_id' => $metadata['object_id'],
                                'object_increment_id' => $metadata['object_increment_id']
                            ]
                        )
                    )
                    ->build();
            }
            return $adjustingReservations;
        }
        catch (\Exception $e) {
            $this->logger->critical(sprintf("RESERVATIONS FIX ERROR: Unable to create Order shipment reservations for item %s", $reservation->getReservationId()));
            $this->logger->critical($e->getMessage());
            return [];
        }
    }
    public function createCreditmemoReservations(ReservationInterface $reservation, $metadata){
        try{
            $adjustingReservations = [];
            $customReservations = $this->getOrderCustomReservationsInStocksSumGroupByStockId($reservation->getSku(), $metadata['object_increment_id'], null);
            foreach ($customReservations as $customReservation){
                if (0 == $customReservation['quantity'] ){
                    continue;
                }
                $newReservationQuantity = $customReservation['quantity'] - $reservation->getQuantity();
                if (0>=$newReservationQuantity){
                    $newReservationQuantity = $reservation->getQuantity();
                }
                else if (0<$newReservationQuantity){
                    $newReservationQuantity = $customReservation['quantity'];
                }

                $adjustingReservations[] = $this->reservationBuilder
                    ->setSku($reservation->getSku())
                    ->setQuantity((float)$newReservationQuantity)
                    ->setStockId((int)$customReservation['stock_id'])
                    ->setMetadata(
                        $this->serializer->serialize(
                            [
                                'event_type' => $metadata['event_type'],
                                'object_type' => 'ca_order_adjust',
                                'object_id' => $metadata['object_id'],
                                'object_increment_id' => $metadata['object_increment_id']
                            ]
                        )
                    )
                    ->build();
            }
            return $adjustingReservations;
        }
        catch (\Exception $e) {
            $this->logger->critical(sprintf("RESERVATIONS FIX ERROR: Unable to create Order creditmemo reservations for item %s", $reservation->getReservationId()));
            $this->logger->critical($e->getMessage());
            return [];
        }
    }
    public function removeInventoryMoveForOrder($incrementId, $reason){
        $inventoryMoveCollection = $this->inventoryCollectionFactory->create();
        $inventoryMoveCollection->addFieldToFilter('increment_id', ['eq' => $incrementId]);
        $inventoryMoveCollection->addFieldToFilter('processed', ['eq' => false]);

        foreach ($inventoryMoveCollection->getItems() as $inventoryMove){
            $inventoryMove->setProcessStatus($reason);
            $inventoryMove->setProcessed(true);
            $this->inventoryMoveResource->save($inventoryMove);
        }
    }
    protected function getSourcesCodesForSku($sku){
        $allSourceCodes = [];
        $allSkuSourceItems = $this->getSourceItemsBySku->execute($sku);
        if (!is_array($allSkuSourceItems)){
            return $allSourceCodes;
        }
        /** @var \Magento\InventoryApi\Api\Data\SourceItemInterface $source */
        foreach ($allSkuSourceItems as $source){
            $allSourceCodes[] = $source->getSourceCode();
        }
        return $allSourceCodes;
    }
    protected function getSourceCodesForSkuInStock($stockId, array $sourceCodes){

        $sourceFilter = $this->filterBuilder->setField('source_code')
                        ->setConditionType('in')
                        ->setValue($sourceCodes)
                        ->create();

        /** @var \Magento\Framework\Api\SearchCriteria $searchCriteria */
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('stock_id',$stockId,'eq')
            ->addFilters([$sourceFilter])
            ->create();
        $stockSources = $this->getStockSourceLinks->execute($searchCriteria);
        $sourceCodes = [];
        /** @var \Magento\Inventory\Model\StockSourceLink $source */
        foreach ($stockSources->getItems() as $source){
            $sourceCodes[] = $source->getSourceCode();
        }
        return $sourceCodes;
    }
    protected function getStocksWithSources($excludeStockId, array $sourceCodes){
        $sourceFilter = $this->filterBuilder->setField('source_code')
                        ->setConditionType('in')
                        ->setValue($sourceCodes)
                        ->create();

        /** @var \Magento\Framework\Api\SearchCriteria $searchCriteria */
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('stock_id',$excludeStockId,'neq')
            ->addFilters([$sourceFilter])
            ->create();
        $stockSources = $this->getStockSourceLinks->execute($searchCriteria);
        $stocksToAdjust = [];
        /** @var \Magento\Inventory\Model\StockSourceLink $source */
        foreach ($stockSources->getItems() as $source){
            if(!array_key_exists($source->getStockId(), $stocksToAdjust)){
                $stocksToAdjust[$source->getStockId()] = $source->getStockId();
            }
        }
        return $stocksToAdjust;
    }
    protected function getNonCustomReservationsForStock($sku, $stockId){
        $connection = $this->resource->getConnection();
        $reservationTable = $this->resource->getTableName('inventory_reservation');
        $query = "select sum(quantity) from ".$reservationTable." where sku='".$sku."' and stock_id=".$stockId." and not JSON_CONTAINS(metadata,'{\"object_type\":\"ca_order_adjust\"}')";
        return $connection->fetchOne($query);
    }
    public function getReservationsForOrder($incrementId, $type){
        $connection = $this->resource->getConnection();
        $reservationTable = $this->resource->getTableName('inventory_reservation');
        $condition = '';
        if ('custom' === $type){
            $condition = " and JSON_CONTAINS(metadata,'{\"object_type\":\"ca_order_adjust\"}')";
        }
        else if ('base' == $type){
            $condition = " and JSON_CONTAINS(metadata,'{\"object_type\":\"order\"}')";
        }
        $query = "select * from ".$reservationTable." where JSON_CONTAINS(metadata,'{\"object_increment_id\":\"".$incrementId."\"}')" . $condition;
        return $connection->fetchAll($query);
    }
    public function getOrdersWithReservations(){
        $connection = $this->resource->getConnection();
        $reservationTable = $this->resource->getTableName('inventory_reservation');
        $query = "select distinct TRIM('\"' FROM JSON_EXTRACT(metadata,\"$.object_increment_id\")) as increment_id from ".$reservationTable;
        return $connection->fetchAll($query);
    }
    protected function getOrderCustomReservations($sku, $incrementId){
        $connection = $this->resource->getConnection();
        $reservationTable = $this->resource->getTableName('inventory_reservation');
        $query = "select * from ".$reservationTable." where sku='".$sku.
            "' and JSON_CONTAINS(metadata,'{\"object_type\":\"ca_order_adjust\"}')".
            " and JSON_CONTAINS(metadata,'{\"event_type\":\"order_placed\"}')".
            " and JSON_CONTAINS(metadata,'{\"object_increment_id\":\"".$incrementId."\"}')";
        return $connection->fetchAll($query);
    }
    protected function getCurrentShipmentSource(){
        return $this->session->getSourceCode();
    }
    protected function getOrderCustomReservationsInStocksSumGroupByStockId($sku, $incrementId, $stockIds){
        $connection = $this->resource->getConnection();
        $reservationTable = $this->resource->getTableName('inventory_reservation');
        $stockIdIn = '';
        if (null !==$stockIds){
            $stockIdIn = " and stock_id in (".join(',',$stockIds).")";
        }
        $query = "select stock_id,sum(quantity) as quantity from ".$reservationTable." where sku='".$sku.
            "' and JSON_CONTAINS(metadata,'{\"object_type\":\"ca_order_adjust\"}')".
            //" and JSON_CONTAINS(metadata,'{\"event_type\":\"order_placed\"}')".
            " and JSON_CONTAINS(metadata,'{\"object_increment_id\":\"".$incrementId."\"}')".
            $stockIdIn.
            " group by stock_id";
        return $connection->fetchAll($query);
    }
    protected function getSourceForSku($sku, $source){
        $sourceItems = $this->getSourceItemsBySku->execute($sku);
        if(is_array($sourceItems)){
            foreach ($sourceItems as $sourceItem){
                if ($source == $sourceItem->getSourceCode()){
                    return $sourceItem;
                }
            }
        }
        return false;
    }
    protected function hasSourceAssigned($sku, $source){
        if ($sourceItem = $this->getSourceForSku($sku, $source)){
            return true;
        }
        return false;
    }
    protected function productIsUnique($sku){
        if ($product = $this->getProduct($sku)){
            if ($attribute = $product->getCustomAttribute('unique')){
                if (true==$attribute->getValue()){
                    return true;
                }
            }
        }
        return false;
    }
    protected function getProduct($sku){
        if (!array_key_exists($sku,$this->productCache)){
            try{
                $this->productCache[$sku] = $this->productRepository->get($sku);
            }
            catch(\Exception $e){
                $this->productCache[$sku] = false;
            }
        }
        return $this->productCache[$sku];
    }
}
