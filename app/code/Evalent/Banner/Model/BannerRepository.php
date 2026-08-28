<?php


namespace Evalent\Banner\Model;

use Evalent\Banner\Api\Data\BannerInterface;
use Evalent\Banner\Api\Data\BannerInterfaceFactory;
use Exception;
use Magento\Framework\Api\ExtensionAttribute\JoinProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\CouldNotSaveException;
use Evalent\Banner\Model\ResourceModel\Banner as ResourceBanner;
use Evalent\Banner\Model\ResourceModel\Banner\CollectionFactory as BannerCollectionFactory;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Reflection\DataObjectProcessor;
use Evalent\Banner\Api\BannerRepositoryInterface;
use Magento\Framework\Api\ExtensibleDataObjectConverter;
use Evalent\Banner\Api\Data\BannerSearchResultsInterfaceFactory;
use Magento\Framework\Exception\NoSuchEntityException;

class BannerRepository implements BannerRepositoryInterface
{

    protected $dataObjectHelper;

    private $storeManager;

    protected $bannerFactory;

    protected $searchResultsFactory;

    protected $dataObjectProcessor;

    protected $extensionAttributesJoinProcessor;

    private $collectionProcessor;

    protected $extensibleDataObjectConverter;

    /** @var \Evalent\Banner\Model\ResourceModel\Banner  */
    protected $resource;

    protected $bannerCollectionFactory;

    /**
     * @var \Magento\Framework\App\State
     */
    private $state;


    /**
     * @param \Magento\Framework\App\State        $state
     * @param ResourceBanner                      $resource
     * @param BannerFactory                       $bannerFactory
     * @param BannerCollectionFactory             $bannerCollectionFactory
     * @param BannerSearchResultsInterfaceFactory $searchResultsFactory
     * @param DataObjectHelper                    $dataObjectHelper
     * @param DataObjectProcessor                 $dataObjectProcessor
     * @param StoreManagerInterface               $storeManager
     * @param CollectionProcessorInterface        $collectionProcessor
     * @param JoinProcessorInterface              $extensionAttributesJoinProcessor
     * @param ExtensibleDataObjectConverter       $extensibleDataObjectConverter
     */
    public function __construct(
        State $state,
        ResourceBanner $resource,
        BannerFactory $bannerFactory,
        BannerCollectionFactory $bannerCollectionFactory,
        BannerSearchResultsInterfaceFactory $searchResultsFactory,
        DataObjectHelper $dataObjectHelper,
        DataObjectProcessor $dataObjectProcessor,
        StoreManagerInterface $storeManager,
        CollectionProcessorInterface $collectionProcessor,
        JoinProcessorInterface $extensionAttributesJoinProcessor,
        ExtensibleDataObjectConverter $extensibleDataObjectConverter
    ) {
        $this->resource = $resource;
        $this->bannerFactory = $bannerFactory;
        $this->bannerCollectionFactory = $bannerCollectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
        $this->dataObjectHelper = $dataObjectHelper;
        $this->dataObjectProcessor = $dataObjectProcessor;
        $this->storeManager = $storeManager;
        $this->collectionProcessor = $collectionProcessor;
        $this->extensionAttributesJoinProcessor = $extensionAttributesJoinProcessor;
        $this->extensibleDataObjectConverter = $extensibleDataObjectConverter;
        $this->state = $state;
    }

    /**
     * {@inheritdoc}
     */
    public function save(
        BannerInterface $banner
    ) {
        if (empty($banner->getStoreId()) && $banner->getStoreId() != 0) {
            if ($this->state->getAreaCode() == Area::AREA_ADMINHTML) {
                $banner->setStoreId(0);
            } else {
                $storeId = (int)$this->storeManager->getStore()->getId();
                $banner->setStoreId($storeId);
            }
        }

        $bannerModel = $this->bannerFactory->create()->setData($banner->getData());

        try {
             $this->resource->save($bannerModel);
        } catch (Exception $exception) {
            throw new CouldNotSaveException(__(
                'Could not save the banner: %1',
                $exception->getMessage()
            ));
        }
        return $this->get($banner->getEntityId());
    }

    /**
     * {@inheritdoc}
     */
    public function get($bannerId)
    {
        $banner = $this->bannerFactory->create();
        $storeId = $this->storeManager->getStore()->getId();
        $banner->setStoreId($storeId);
        $this->resource->load($banner, $bannerId);
        if (!$banner->getId()) {
            throw new NoSuchEntityException(__('Banner with id "%1" does not exist.', $bannerId));
        }
        return $banner->getDataModel();
    }

    /**
     * {@inheritdoc}
     */
    public function getList(
        SearchCriteriaInterface $criteria
    ) {
        $collection = $this->bannerCollectionFactory->create();

        $this->extensionAttributesJoinProcessor->process(
            $collection,
            BannerInterface::class
        );

        $this->collectionProcessor->process($criteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($criteria);

        $items = [];
        foreach ($collection as $model) {
            $items[] = $model->getDataModel();
        }

        $searchResults->setItems($items);
        $searchResults->setTotalCount($collection->getSize());
        return $searchResults;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(
        BannerInterface $banner
    ) {
        try {
            $bannerModel = $this->bannerFactory->create();
            $this->resource->load($bannerModel, $banner->getEntityId());
            $this->resource->delete($bannerModel);
        } catch (Exception $exception) {
            throw new CouldNotDeleteException(__(
                'Could not delete the Banner: %1',
                $exception->getMessage()
            ));
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteById($bannerId)
    {
        return $this->delete($this->get($bannerId));
    }
}
