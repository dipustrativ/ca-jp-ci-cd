<?php

namespace Evalent\AdminActivityLog\Observer\Model;

use Evalent\AdminActivityLog\Api\ModelActivityRepositoryInterface;
use Evalent\AdminActivityLog\Api\CmsActivityRepositoryInterface;
use Evalent\AdminActivityLog\Model\ModelActivityFactory;
use Evalent\AdminActivityLog\Model\CmsActivityFactory;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class DeleteAfter implements \Magento\Framework\Event\ObserverInterface
{

    const LOGGER_PREFIX = 'Save after model: ';

    /**
     * @var \Evalent\AdminActivityLog\Controller\Adminhtml\ModelActivityInterfaceFactory
     */
    private $modelActivityFactory;

    /**
     * @var \Evalent\AdminActivityLog\Api\ModelActivityRepositoryInterface
     */
    private $modelActivityRepository;

    /**
     * @var \Magento\Backend\Model\Session
     */
    private $adminSession;

    /**
     * @var \Evalent\AdminActivityLog\Model\ModelActivityFactory
     */
    private $cmsActivityFactory;

    /**
     * @var \Evalent\AdminActivityLog\Api\CmsActivityRepositoryInterface
     */
    private $cmsActivityRepository;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var array
     */
    private $ignoreResourceModel;

    /**
     * DeleteAfter constructor.
     *
     * @param \Magento\Backend\Model\Auth\Session                            $adminSession
     * @param \Evalent\AdminActivityLog\Model\ModelActivityFactory           $modelActivityFactory
     * @param \Evalent\AdminActivityLog\Model\CmsActivityFactory             $cmsActivityFactory
     * @param \Evalent\AdminActivityLog\Api\ModelActivityRepositoryInterface $modelActivityRepository
     * @param \Evalent\AdminActivityLog\Api\CmsActivityRepositoryInterface   $cmsActivityRepository
     * @param \Psr\Log\LoggerInterface                                       $logger
     * @param array                                                          $ignoreResourceModel
     */
    public function __construct(
        Session $adminSession,
        ModelActivityFactory $modelActivityFactory,
        CmsActivityFactory $cmsActivityFactory,
        ModelActivityRepositoryInterface $modelActivityRepository,
        CmsActivityRepositoryInterface $cmsActivityRepository,
        LoggerInterface $logger,
        array $ignoreResourceModel = []
    ) {
        $this->modelActivityFactory = $modelActivityFactory;
        $this->modelActivityRepository = $modelActivityRepository;
        $this->adminSession = $adminSession;
        $this->cmsActivityFactory = $cmsActivityFactory;
        $this->cmsActivityRepository = $cmsActivityRepository;
        $this->logger = $logger;
        $this->ignoreResourceModel = $ignoreResourceModel;
    }

    /**
     * Execute observer
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(
        \Magento\Framework\Event\Observer $observer
    ) {
        if (!$this->adminSession->isLoggedIn()) {
            return;
        }
        /** @var \Magento\Framework\Model\AbstractModel $model */
        $model = $observer->getData('object');
        if (in_array($model->getResourceName(), $this->ignoreResourceModel)) {
            return;
        }
        switch ($model->getEventPrefix()) {
            case 'catalog_product':
                $this->productModel($model);
                break;
            case 'cms_page':
            case 'cms_block':
                $this->cmsModel($model);
                break;
            case 'core_abstract':
                break;
            default:
                $this->productModel($model);
                break;
        }
    }


    /**
     * @param \Magento\Catalog\Model\AbstractModel $model
     */
    private function productModel($model)
    {
        /** @var \Evalent\AdminActivityLog\Model\ModelActivity $activity */
        $activity = $this->modelActivityFactory->create();

        /** @var \Magento\User\Model\User $adminUser */
        $adminUser = $this->adminSession->getUser();
        $actionType = 'Delete';
        $deleteData = $model->getData('name');

        $idFieldName = $model->getIdFieldName();
        $activity->setActionType($actionType);
        $activity->setUsername($adminUser->getUserName());
        $activity->setFullName($adminUser->getName());
        $activity->setDatetime(date('Y-m-d H:i:s'));
        $activity->setModelType($model->getResourceName());
        $activity->setModelEntityId($model->getData($idFieldName));
        $activity->setEditedAttributes(json_encode(['name' => ['old_data' => $deleteData, 'new_data' => ""]]));

        try {
            $this->modelActivityRepository->save($activity);
        } catch (\Exception $e) {
            $this->logger->error(self::LOGGER_PREFIX
                . "Couldn't save activity. " . $e->getMessage());
        }
    }

    /**
     * @param \Magento\Catalog\Model\AbstractModel $model
     */
    private function cmsModel($model)
    {
        /** @var \Evalent\AdminActivityLog\Model\CmsActivity $activity */
        $activity = $this->cmsActivityFactory->create();

        /** @var \Magento\User\Model\User $adminUser */
        $adminUser = $this->adminSession->getUser();
        $actionType = 'Delete';
        $deleteData = $model->getData('name');

        $activity->setActionType($actionType);
        $activity->setUsername($adminUser->getUserName());
        $activity->setFullName($adminUser->getName());
        $activity->setDatetime(date('Y-m-d H:i:s'));
        $activity->setTitle($model->getTitle());
        $activity->setEditedAttributes(json_encode(['name' => ['old_data' => $deleteData, 'new_data' => ""]]));

        try {
            $this->cmsActivityRepository->save($activity);
        } catch (\Exception $e) {
            $this->logger->error(self::LOGGER_PREFIX
                . "Couldn't save activity. " . $e->getMessage());
        }
    }
}