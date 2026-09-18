<?php

namespace CarpetAvenue\ReservationsFix\Controller\Adminhtml\MoveSource;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Ui\Component\MassAction\Filter;
use CarpetAvenue\ReservationsFix\Model\ResourceModel\InventoryMove\CollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use CarpetAvenue\ReservationsFix\Api\ReservationsManagerInterface;

class MassDelete extends Action{

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
                $this->reservationsManager->removeInventoryMove($move, 'Manually deleted');
            }
            $this->messageManager->addSuccessMessage(__('A total of %1 record(s) have been deleted.', $collectionSize));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('An error occurred while deleting record(s).'));
        }
        $redirectResult = $this->resultRedirectFactory->create();
        $redirectResult->setPath('*/*/index');
        return $redirectResult;
    }
}