<?php

namespace CarpetAvenue\ReservationsFix\Controller\Adminhtml\MoveSource;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Ui\Component\MassAction\Filter;
use CarpetAvenue\ReservationsFix\Model\ResourceModel\InventoryMove\CollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use CarpetAvenue\ReservationsFix\Api\ReservationsManagerInterface;

class MassFixReservationDestination extends Action{

    protected Filter $filter;
    protected CollectionFactory $collectionFactory;
    protected ReservationsManagerInterface $reservationsManager;

    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        ReservationsManagerInterface $reservationsManager
    )
    {
        parent::__construct($context);
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->reservationsManager = $reservationsManager;
    }
    public function execute()
    {
        try {
            $moves = $this->collectionFactory->create();
            $collection = $this->filter->getCollection($moves);
            $collectionSize = $collection->getSize();

            foreach ($collection as $move) {
                //$this->reservationsManager->moveInventory($move, 'Moved');
                if ($move->getProcessed()){
                    continue;
                }
                $this->reservationsManager->increaseDestination($move);
                $this->reservationsManager->moveInventoryAssociatedResevations($move);
                $this->reservationsManager->setProcessed($move, 'Reservation fixed, destination increased');
            }
            $this->messageManager->addSuccessMessage(__('A total of %1 record(s) have been fixed and increased at destination.', $collectionSize));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('An error occurred while fixing and increasing records(s).'));
        }
        $redirectResult = $this->resultRedirectFactory->create();
        $redirectResult->setPath('*/*/index');
        return $redirectResult;
    }
}