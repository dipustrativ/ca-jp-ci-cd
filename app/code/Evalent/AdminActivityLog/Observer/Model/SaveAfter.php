<?php

namespace Evalent\AdminActivityLog\Observer\Model;

use Evalent\AdminActivityLog\Api\CmsActivityRepositoryInterface;
use Evalent\AdminActivityLog\Api\ConfigActivityRepositoryInterface;
use Evalent\AdminActivityLog\Api\ModelActivityRepositoryInterface;
use Evalent\AdminActivityLog\Model\CmsActivityFactory;
use Evalent\AdminActivityLog\Model\DiffCheckers\Model as ModelDiffChecker;
use Evalent\AdminActivityLog\Model\ModelActivityFactory;
use Evalent\AdminActivityLog\Model\ResourceModel\ConfigActivityFactory;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Psr\Log\LoggerInterface;

class SaveAfter implements \Magento\Framework\Event\ObserverInterface
{
    const LOGGER_PREFIX = 'Save after model: ';

    /**
     * Ignore there attributes changed due to always being true
     */
    const IGNORE_ATTRIBUTES
        = [
            'updated_at',
            'media_gallery',
            'website_ids',
            'quantity_and_stock_status',
            '_cache_instance_product_set_attributes',
            'stock_data',
        ];

    const TRANSLATED_MODEL_NAMES
        = [
            'catalog_product'  => 'Product',
            'catalog_category' => 'Category',
        ];

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
     * @var \Evalent\AdminActivityLog\Model\CmsActivityFactory
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
     * @var \Magento\Customer\Model\ResourceModel\Customer
     */
    private $customerRepository;

    /**
     * @var array
     */
    private $ignoreResourceModel;

    /**
     * @var \Evalent\AdminActivityLog\Model\ResourceModel\ConfigActivityFactory
     */
    private $configActivityFactory;

    /**
     * @var \Evalent\AdminActivityLog\Api\ConfigActivityRepositoryInterface
     */
    private $configActivityRepository;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $config;

    /**
     * @var \Evalent\AdminActivityLog\Model\DiffCheckers\Model
     */
    private $modelDiffChecker;

    /**
     * SaveAfter constructor.
     *
     * @param \Magento\Backend\Model\Auth\Session                                 $adminSession
     * @param \Evalent\AdminActivityLog\Model\ModelActivityFactory                $modelActivityFactory
     * @param \Evalent\AdminActivityLog\Model\CmsActivityFactory                  $cmsActivityFactory
     * @param \Evalent\AdminActivityLog\Model\ResourceModel\ConfigActivityFactory $configActivityFactory
     * @param \Evalent\AdminActivityLog\Api\ConfigActivityRepositoryInterface     $configActivityRepository
     * @param \Magento\Framework\App\Config\ScopeConfigInterface                  $config
     * @param \Evalent\AdminActivityLog\Api\ModelActivityRepositoryInterface      $modelActivityRepository
     * @param \Evalent\AdminActivityLog\Api\CmsActivityRepositoryInterface        $cmsActivityRepository
     * @param \Magento\Customer\Model\ResourceModel\Customer                      $customerRepository
     * @param \Psr\Log\LoggerInterface                                            $logger
     * @param array                                                               $ignoreResourceModel
     * @param \Evalent\AdminActivityLog\Model\DiffCheckers\Model                  $modelDiffChecker
     */
    public function __construct(
        Session $adminSession,
        ModelActivityFactory $modelActivityFactory,
        CmsActivityFactory $cmsActivityFactory,
        ConfigActivityFactory $configActivityFactory,
        ConfigActivityRepositoryInterface $configActivityRepository,
        ScopeConfigInterface $config,
        ModelActivityRepositoryInterface $modelActivityRepository,
        CmsActivityRepositoryInterface $cmsActivityRepository,
        \Magento\Customer\Model\ResourceModel\Customer $customerRepository,
        LoggerInterface $logger,
        array $ignoreResourceModel = [],
        ?ModelDiffChecker $modelDiffChecker = null
    ) {
        $this->modelActivityFactory = $modelActivityFactory;
        $this->modelActivityRepository = $modelActivityRepository;
        $this->adminSession = $adminSession;
        $this->cmsActivityFactory = $cmsActivityFactory;
        $this->cmsActivityRepository = $cmsActivityRepository;
        $this->logger = $logger;
        $this->customerRepository = $customerRepository;
        $this->ignoreResourceModel = $ignoreResourceModel;
        $this->configActivityFactory = $configActivityFactory;
        $this->configActivityRepository = $configActivityRepository;
        $this->config = $config;
        $this->modelDiffChecker = $modelDiffChecker ?? ObjectManager::getInstance()->get(ModelDiffChecker::class);
    }

    /**
     * Execute observer
     *
     * @param \Magento\Framework\Event\Observer $observer
     *
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
            case 'catalog_category':
                $this->catalogModel($model);
                break;
            case 'cms_page':
            case 'cms_block':
                $this->cmsModel($model);
                break;
            case 'core_abstract':
            case 'cataloginventory_stock':
                break;
            default:
                $this->catalogModel($model);
                break;
        }
    }

    /**
     * @param \Magento\Catalog\Model\AbstractModel $model
     */
    private function catalogModel($model)
    {
        /** @var \Evalent\AdminActivityLog\Model\ModelActivity $activity */
        $activity = $this->modelActivityFactory->create();

        /** @var \Magento\User\Model\User $adminUser */
        $adminUser = $this->adminSession->getUser();
        $actionType = 'Save';
        if ($model->isObjectNew()) {
            $actionType = 'New';
        }

        $changedAttributes = $this->modelDiffChecker->checkDifferences($model);
        if ($actionType == "New") {
            $changedAttributes = [];
            foreach ($model->getData() as $key => $value) {
                if (in_array($key, self::IGNORE_ATTRIBUTES)) {
                    continue;
                }
                if ($value != "" && $value != null) {
                    $changedAttributes[$key] = [
                        'old_data' => "null",
                        'new_data' => $value,
                    ];
                }
            }
        }

        if (sizeof($changedAttributes) == 0) {
            return;
        }

        $idFieldName = $model->getIdFieldName();
        $activity->setActionType($actionType);
        $activity->setUsername($adminUser->getUserName());
        $activity->setFullName($adminUser->getName());
        $activity->setModelEntityId($model->getData($idFieldName));
        $activity->setDatetime(date('Y-m-d H:i:s'));
        $activity->setModelType($model->getResourceName());
        $activity->setEditedAttributes(json_encode($changedAttributes));

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
    private function cmsModel(
        $model
    ) {
        /** @var \Evalent\AdminActivityLog\Model\CmsActivity $activity */
        $activity = $this->cmsActivityFactory->create();

        /** @var \Magento\User\Model\User $adminUser */
        $adminUser = $this->adminSession->getUser();
        $actionType = 'Save';
        if ($model->isObjectNew()) {
            $actionType = 'New';
        }

        $changedAttributes = [];
        $oldData = $model->getOrigData();
        $newData = $model->getData();

        if ($oldData) {
            foreach ($oldData as $key => $value) {
                if (in_array($key, self::IGNORE_ATTRIBUTES)) {
                    continue;
                }
                if (isset($newData[$key]) && $key != 'content') {
                    if ($oldData[$key] != $newData[$key]) {
                        $changedAttributes[$key] = [
                            'old_data' => $oldData[$key],
                            'new_data' => $newData[$key],
                        ];
                    }
                }
            }
        }
        $activity->setActionType($actionType);
        $activity->setUsername($adminUser->getUserName());
        $activity->setFullName($adminUser->getName());
        $activity->setTitle($model->getTitle());
        $activity->setDatetime(date('Y-m-d H:i:s'));
        if ($oldData !== null && $oldData['content'] != $newData['content']) {
            $activity->setOldContent($oldData['content']);
            $activity->setNewContent($newData['content']);
        }
        $activity->setEditedAttributes(json_encode($changedAttributes));

        try {
            $this->cmsActivityRepository->save($activity);
        } catch (\Exception $e) {
            $this->logger->error(self::LOGGER_PREFIX
                . "Couldn't save activity. " . $e->getMessage());
        }
    }
}
