<?php


namespace Evalent\AdminActivityLog\Model;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Evalent\AdminActivityLog\Model\ResourceModel\ModelActivity as ResourceModelActivity;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Api\SortOrder;
use Evalent\AdminActivityLog\Api\Data\ModelActivitySearchResultsInterfaceFactory;
use Magento\Framework\Api\DataObjectHelper;
use Evalent\AdminActivityLog\Api\Data\ModelActivityInterfaceFactory;
use Magento\Framework\Reflection\DataObjectProcessor;
use Magento\Framework\Exception\NoSuchEntityException;
use Evalent\AdminActivityLog\Api\ModelActivityRepositoryInterface;
use Evalent\AdminActivityLog\Model\ResourceModel\ModelActivity\CollectionFactory as ModelActivityCollectionFactory;

class ModelActivityRepository implements ModelActivityRepositoryInterface
{

    protected $modelActivityFactory;

    protected $dataObjectProcessor;

    protected $searchResultsFactory;

    private $storeManager;

    protected $modelActivityCollectionFactory;

    protected $dataModelActivityFactory;

    protected $resource;

    protected $dataObjectHelper;


    /**
     * @param ResourceModelActivity $resource
     * @param ModelActivityFactory $modelActivityFactory
     * @param ModelActivityInterfaceFactory $dataModelActivityFactory
     * @param ModelActivityCollectionFactory $modelActivityCollectionFactory
     * @param ModelActivitySearchResultsInterfaceFactory $searchResultsFactory
     * @param DataObjectHelper $dataObjectHelper
     * @param DataObjectProcessor $dataObjectProcessor
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        ResourceModelActivity $resource,
        ModelActivityFactory $modelActivityFactory,
        ModelActivityInterfaceFactory $dataModelActivityFactory,
        ModelActivityCollectionFactory $modelActivityCollectionFactory,
        ModelActivitySearchResultsInterfaceFactory $searchResultsFactory,
        DataObjectHelper $dataObjectHelper,
        DataObjectProcessor $dataObjectProcessor,
        StoreManagerInterface $storeManager
    ) {
        $this->resource = $resource;
        $this->modelActivityFactory = $modelActivityFactory;
        $this->modelActivityCollectionFactory = $modelActivityCollectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
        $this->dataObjectHelper = $dataObjectHelper;
        $this->dataModelActivityFactory = $dataModelActivityFactory;
        $this->dataObjectProcessor = $dataObjectProcessor;
        $this->storeManager = $storeManager;
    }

    /**
     * {@inheritdoc}
     */
    public function save(
        \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface $modelActivity
    ) {
        /* if (empty($modelActivity->getStoreId())) {
            $storeId = $this->storeManager->getStore()->getId();
            $modelActivity->setStoreId($storeId);
        } */
        try {
            $this->resource->save($modelActivity);
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(__(
                'Could not save the modelActivity: %1',
                $exception->getMessage()
            ));
        }
        return $modelActivity;
    }

    /**
     * {@inheritdoc}
     */
    public function getById($modelActivityId)
    {
        $modelActivity = $this->modelActivityFactory->create();
        $this->resource->load($modelActivity, $modelActivityId);
        if (!$modelActivity->getId()) {
            throw new NoSuchEntityException(__('ModelActivity with id "%1" does not exist.', $modelActivityId));
        }
        return $modelActivity;
    }

    /**
     * {@inheritdoc}
     */
    public function getList(
        \Magento\Framework\Api\SearchCriteriaInterface $criteria
    ) {
        $collection = $this->modelActivityCollectionFactory->create();
        foreach ($criteria->getFilterGroups() as $filterGroup) {
            $fields = [];
            $conditions = [];
            foreach ($filterGroup->getFilters() as $filter) {
                if ($filter->getField() === 'store_id') {
                    $collection->addStoreFilter($filter->getValue(), false);
                    continue;
                }
                $fields[] = $filter->getField();
                $condition = $filter->getConditionType() ?: 'eq';
                $conditions[] = [$condition => $filter->getValue()];
            }
            $collection->addFieldToFilter($fields, $conditions);
        }
        
        $sortOrders = $criteria->getSortOrders();
        if ($sortOrders) {
            /** @var SortOrder $sortOrder */
            foreach ($sortOrders as $sortOrder) {
                $collection->addOrder(
                    $sortOrder->getField(),
                    ($sortOrder->getDirection() == SortOrder::SORT_ASC) ? 'ASC' : 'DESC'
                );
            }
        }
        $collection->setCurPage($criteria->getCurrentPage());
        $collection->setPageSize($criteria->getPageSize());
        
        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($criteria);
        $searchResults->setTotalCount($collection->getSize());
        $searchResults->setItems($collection->getItems());
        return $searchResults;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(
        \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface $modelActivity
    ) {
        try {
            $this->resource->delete($modelActivity);
        } catch (\Exception $exception) {
            throw new CouldNotDeleteException(__(
                'Could not delete the ModelActivity: %1',
                $exception->getMessage()
            ));
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteById($modelActivityId)
    {
        return $this->delete($this->getById($modelActivityId));
    }
}
