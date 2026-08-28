<?php


namespace Evalent\AdminActivityLog\Model;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Evalent\AdminActivityLog\Model\ResourceModel\CmsActivity as ResourceCmsActivity;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Api\SortOrder;
use Evalent\AdminActivityLog\Api\Data\CmsActivitySearchResultsInterfaceFactory;
use Magento\Framework\Api\DataObjectHelper;
use Evalent\AdminActivityLog\Api\Data\CmsActivityInterfaceFactory;
use Magento\Framework\Reflection\DataObjectProcessor;
use Magento\Framework\Exception\NoSuchEntityException;
use Evalent\AdminActivityLog\Api\CmsActivityRepositoryInterface;
use Evalent\AdminActivityLog\Model\ResourceModel\CmsActivity\CollectionFactory as CmsActivityCollectionFactory;

class CmsActivityRepository implements CmsActivityRepositoryInterface
{

    protected $cmsActivityFactory;

    protected $dataObjectProcessor;

    protected $searchResultsFactory;

    private $storeManager;

    protected $cmsActivityCollectionFactory;

    protected $dataCmsActivityFactory;

    protected $resource;

    protected $dataObjectHelper;


    /**
     * @param ResourceModelActivity                                                       $resource
     * @param \Evalent\AdminActivityLog\Model\CmsActivityFactory                          $cmsActivityFactory
     * @param \Evalent\AdminActivityLog\Api\Data\CmsActivityInterfaceFactory              $dataCmsActivityFactory
     * @param \Evalent\AdminActivityLog\Model\ResourceModel\CmsActivity\CollectionFactory $cmsActivityCollectionFactory
     * @param \Evalent\AdminActivityLog\Api\Data\CmsActivitySearchResultsInterfaceFactory $searchResultsFactory
     * @param DataObjectHelper                                                            $dataObjectHelper
     * @param DataObjectProcessor                                                         $dataObjectProcessor
     * @param StoreManagerInterface                                                       $storeManager
     */
    public function __construct(
        ResourceCmsActivity $resource,
        CmsActivityFactory $cmsActivityFactory,
        CmsActivityInterfaceFactory $dataCmsActivityFactory,
        CmsActivityCollectionFactory $cmsActivityCollectionFactory,
        CmsActivitySearchResultsInterfaceFactory $searchResultsFactory,
        DataObjectHelper $dataObjectHelper,
        DataObjectProcessor $dataObjectProcessor,
        StoreManagerInterface $storeManager
    ) {
        $this->resource = $resource;
        $this->cmsActivityFactory = $cmsActivityFactory;
        $this->cmsActivityCollectionFactory = $cmsActivityCollectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
        $this->dataObjectHelper = $dataObjectHelper;
        $this->dataCmsActivityFactory = $dataCmsActivityFactory;
        $this->dataObjectProcessor = $dataObjectProcessor;
        $this->storeManager = $storeManager;
    }

    /**
     * {@inheritdoc}
     */
    public function save(
        \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface $cmsActivity
    ) {
        /* if (empty($modelActivity->getStoreId())) {
            $storeId = $this->storeManager->getStore()->getId();
            $modelActivity->setStoreId($storeId);
        } */
        try {
            $this->resource->save($cmsActivity);
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(__(
                'Could not save the Cms Activity: %1',
                $exception->getMessage()
            ));
        }
        return $cmsActivity;
    }

    /**
     * {@inheritdoc}
     */
    public function getById($cmsActivityId)
    {
        $cmsActivity = $this->cmsActivityFactory->create();
        $this->resource->load($cmsActivity, $cmsActivityId);
        if (!$cmsActivity->getId()) {
            throw new NoSuchEntityException(__('CmsActivity with id "%1" does not exist.', $cmsActivityId));
        }
        return $cmsActivity;
    }

    /**
     * {@inheritdoc}
     */
    public function getList(
        \Magento\Framework\Api\SearchCriteriaInterface $criteria
    ) {
        $collection = $this->cmsActivityCollectionFactory->create();
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
        \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface $cmsActivity
    ) {
        try {
            $this->resource->delete($cmsActivity);
        } catch (\Exception $exception) {
            throw new CouldNotDeleteException(__(
                'Could not delete the CmsActivity: %1',
                $exception->getMessage()
            ));
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteById($cmsActivityId)
    {
        return $this->delete($this->getById($cmsActivityId));
    }
}
