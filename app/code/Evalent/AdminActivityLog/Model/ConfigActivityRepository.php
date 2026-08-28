<?php


namespace Evalent\AdminActivityLog\Model;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Evalent\AdminActivityLog\Model\ResourceModel\ConfigActivity as ResourceConfigActivity;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Api\SortOrder;
use Evalent\AdminActivityLog\Api\Data\ConfigActivitySearchResultsInterfaceFactory;
use Magento\Framework\Api\DataObjectHelper;
use Evalent\AdminActivityLog\Api\Data\ConfigActivityInterfaceFactory;
use Magento\Framework\Reflection\DataObjectProcessor;
use Magento\Framework\Exception\NoSuchEntityException;
use Evalent\AdminActivityLog\Api\ConfigActivityRepositoryInterface;
use Evalent\AdminActivityLog\Model\ResourceModel\ConfigActivity\CollectionFactory as ConfigActivityCollectionFactory;

class ConfigActivityRepository implements ConfigActivityRepositoryInterface
{

    protected $configActivityFactory;

    protected $dataObjectProcessor;

    protected $searchResultsFactory;

    private $storeManager;

    protected $configActivityCollectionFactory;

    protected $dataConfigActivityFactory;

    protected $resource;

    protected $dataObjectHelper;


    /**
     * @param ResourceConfigActivity $resource
     * @param ConfigActivityFactory $configActivityFactory
     * @param ConfigActivityInterfaceFactory $dataConfigActivityFactory
     * @param ConfigActivityCollectionFactory $configActivityCollectionFactory
     * @param ConfigActivitySearchResultsInterfaceFactory $searchResultsFactory
     * @param DataObjectHelper $dataObjectHelper
     * @param DataObjectProcessor $dataObjectProcessor
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        ResourceConfigActivity $resource,
        ConfigActivityFactory $configActivityFactory,
        ConfigActivityInterfaceFactory $dataConfigActivityFactory,
        ConfigActivityCollectionFactory $configActivityCollectionFactory,
        ConfigActivitySearchResultsInterfaceFactory $searchResultsFactory,
        DataObjectHelper $dataObjectHelper,
        DataObjectProcessor $dataObjectProcessor,
        StoreManagerInterface $storeManager
    ) {
        $this->resource = $resource;
        $this->configActivityFactory = $configActivityFactory;
        $this->configActivityCollectionFactory = $configActivityCollectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
        $this->dataObjectHelper = $dataObjectHelper;
        $this->dataConfigActivityFactory = $dataConfigActivityFactory;
        $this->dataObjectProcessor = $dataObjectProcessor;
        $this->storeManager = $storeManager;
    }

    /**
     * {@inheritdoc}
     */
    public function save(
        \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterface $configActivity
    ) {
        try {
            $this->resource->save($configActivity);
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(__(
                'Could not save the modelActivity: %1',
                $exception->getMessage()
            ));
        }
        return $configActivity;
    }

    /**
     * {@inheritdoc}
     */
    public function getById($configActivityId)
    {
        $configActivity = $this->configActivityFactory->create();
        $this->resource->load($configActivity, $configActivityId);
        if (!$configActivity->getId()) {
            throw new NoSuchEntityException(__('ConfigActivity with id "%1" does not exist.', $configActivityId));
        }
        return $configActivity;
    }

    /**
     * {@inheritdoc}
     */
    public function getList(
        \Magento\Framework\Api\SearchCriteriaInterface $criteria
    ) {
        $collection = $this->configActivityCollectionFactory->create();
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
        \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterface $configActivity
    ) {
        try {
            $this->resource->delete($configActivity);
        } catch (\Exception $exception) {
            throw new CouldNotDeleteException(__(
                'Could not delete the ConfigActivity: %1',
                $exception->getMessage()
            ));
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteById($configActivityId)
    {
        return $this->delete($this->getById($configActivityId));
    }
}
