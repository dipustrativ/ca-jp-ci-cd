<?php


namespace Evalent\AdminActivityLog\Model;

use Magento\Store\Model\StoreManagerInterface;
use Evalent\AdminActivityLog\Api\LoginActivityRepositoryInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Evalent\AdminActivityLog\Api\Data\LoginActivitySearchResultsInterfaceFactory;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\Reflection\DataObjectProcessor;
use Magento\Framework\Exception\NoSuchEntityException;
use Evalent\AdminActivityLog\Model\ResourceModel\LoginActivity as ResourceLoginActivity;
use Evalent\AdminActivityLog\Model\ResourceModel\LoginActivity\CollectionFactory as LoginActivityCollectionFactory;
use Evalent\AdminActivityLog\Api\Data\LoginActivityInterfaceFactory;

class LoginActivityRepository implements LoginActivityRepositoryInterface
{

    protected $loginActivityCollectionFactory;

    protected $loginActivityFactory;

    protected $dataLoginActivityFactory;

    protected $dataObjectProcessor;

    protected $searchResultsFactory;

    private $storeManager;

    protected $resource;

    protected $dataObjectHelper;


    /**
     * @param ResourceLoginActivity $resource
     * @param LoginActivityFactory $loginActivityFactory
     * @param LoginActivityInterfaceFactory $dataLoginActivityFactory
     * @param LoginActivityCollectionFactory $loginActivityCollectionFactory
     * @param LoginActivitySearchResultsInterfaceFactory $searchResultsFactory
     * @param DataObjectHelper $dataObjectHelper
     * @param DataObjectProcessor $dataObjectProcessor
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        ResourceLoginActivity $resource,
        LoginActivityFactory $loginActivityFactory,
        LoginActivityInterfaceFactory $dataLoginActivityFactory,
        LoginActivityCollectionFactory $loginActivityCollectionFactory,
        LoginActivitySearchResultsInterfaceFactory $searchResultsFactory,
        DataObjectHelper $dataObjectHelper,
        DataObjectProcessor $dataObjectProcessor,
        StoreManagerInterface $storeManager
    ) {
        $this->resource = $resource;
        $this->loginActivityFactory = $loginActivityFactory;
        $this->loginActivityCollectionFactory = $loginActivityCollectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
        $this->dataObjectHelper = $dataObjectHelper;
        $this->dataLoginActivityFactory = $dataLoginActivityFactory;
        $this->dataObjectProcessor = $dataObjectProcessor;
        $this->storeManager = $storeManager;
    }

    /**
     * {@inheritdoc}
     */
    public function save(
        \Evalent\AdminActivityLog\Api\Data\LoginActivityInterface $loginActivity
    ) {
        /* if (empty($loginActivity->getStoreId())) {
            $storeId = $this->storeManager->getStore()->getId();
            $loginActivity->setStoreId($storeId);
        } */
        try {
            $this->resource->save($loginActivity);
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(__(
                'Could not save the loginActivity: %1',
                $exception->getMessage()
            ));
        }
        return $loginActivity;
    }

    /**
     * {@inheritdoc}
     */
    public function getById($loginActivityId)
    {
        $loginActivity = $this->loginActivityFactory->create();
        $this->resource->load($loginActivity, $loginActivityId);
        if (!$loginActivity->getId()) {
            throw new NoSuchEntityException(__('LoginActivity with id "%1" does not exist.', $loginActivityId));
        }
        return $loginActivity;
    }

    /**
     * {@inheritdoc}
     */
    public function getList(
        \Magento\Framework\Api\SearchCriteriaInterface $criteria
    ) {
        $collection = $this->loginActivityCollectionFactory->create();
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
        \Evalent\AdminActivityLog\Api\Data\LoginActivityInterface $loginActivity
    ) {
        try {
            $this->resource->delete($loginActivity);
        } catch (\Exception $exception) {
            throw new CouldNotDeleteException(__(
                'Could not delete the LoginActivity: %1',
                $exception->getMessage()
            ));
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteById($loginActivityId)
    {
        return $this->delete($this->getById($loginActivityId));
    }
}
