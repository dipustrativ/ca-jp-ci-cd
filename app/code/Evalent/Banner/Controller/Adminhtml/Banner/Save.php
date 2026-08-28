<?php

declare(strict_types=1);

namespace Evalent\Banner\Controller\Adminhtml\Banner;

use Evalent\Banner\Api\BannerRepositoryInterface;
use Evalent\Banner\Api\Data\BannerInterfaceFactory;
use Evalent\Banner\Model\BannerFactory;
use Evalent\Banner\Model\UploaderPool;
use Exception;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

class Save extends Action
{

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;


    protected $dataPersistor;

    /**
     * @var \Evalent\Banner\Model\UploaderPool
     */
    private $uploaderPool;

    /**
     * @var \Evalent\Banner\Api\BannerRepositoryInterface
     */
    private $bannerRepository;

    /**
     * @var \Evalent\Banner\Api\Data\BannerInterfaceFactory
     */
    private $bannerInterfaceFactory;


    /**
     * @param \Magento\Store\Model\StoreManagerInterface            $storeManager
     * @param \Evalent\Banner\Model\UploaderPool                    $uploaderPool
     * @param \Evalent\Banner\Api\BannerRepositoryInterface         $bannerRepository
     * @param \Evalent\Banner\Api\Data\BannerInterfaceFactory       $bannerInterfaceFactory
     * @param \Magento\Backend\App\Action\Context                   $context
     * @param \Magento\Framework\App\Request\DataPersistorInterface $dataPersistor
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        UploaderPool $uploaderPool,
        BannerRepositoryInterface $bannerRepository,
        BannerInterfaceFactory $bannerInterfaceFactory,
        Context $context,
        DataPersistorInterface $dataPersistor
    ) {
        $this->dataPersistor = $dataPersistor;
        parent::__construct($context);
        $this->uploaderPool = $uploaderPool;
        $this->bannerRepository = $bannerRepository;
        $this->bannerInterfaceFactory = $bannerInterfaceFactory;
        $this->storeManager = $storeManager;
    }

    /**
     * Save action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute()
    {
        $storeId = (int)$this->getRequest()->getParam('store');
        $store = $this->storeManager->getStore($storeId);
        $this->storeManager->setCurrentStore($store->getCode());
        $params = [];
        $params['store'] = $storeId;
        /** @var \Magento\Backend\Model\View\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();
        $data = $this->getRequest()->getPostValue();
        if ($data) {
            $id = $this->getRequest()->getParam('entity_id');

            /** @var \Evalent\Banner\Api\Data\BannerInterface $model */
            try {
                $model = $this->bannerRepository->get($id);
                $params['entity_id'] = $data['entity_id'];
            } catch (LocalizedException $e) {
                $model = $this->bannerInterfaceFactory->create();
                $data['entity_id'] = null;
            }

            if (!$model->getEntityId() && $id) {
                $this->messageManager->addErrorMessage(__('This Banner no longer exists.'));
                return $resultRedirect->setPath('*/*/');
            }

            try {
                $uploader = $this->uploaderPool->getUploader('image');
                /**
                 * Upload image if changed
                 */
                if (isset($data['desktop_image']) && isset($data['desktop_image'][0]['tmp_name'])) {
                    $imageName = $uploader->uploadFileAndGetName($data['desktop_image'], 'desktop_image');
                    $data['desktop_image'] = $imageName;
                } else {
                    unset($data['desktop_image']);
                }

                /**
                 * Upload image if changed
                 */
                if (isset($data['mobile_image']) && isset($data['mobile_image'][0]['tmp_name'])) {
                    $imageName = $uploader->uploadFileAndGetName($data['mobile_image'], 'mobile_image');
                    $data['mobile_image'] = $imageName;
                } else {
                    unset($data['mobile_image']);
                }
                $model->setStoreId($storeId);
                $model->addData($data);

                /**
                 * Check "Use Default Value" checkboxes values
                 */
                if (isset($data['use_default']) && !empty($data['use_default'])) {
                    foreach ($data['use_default'] as $attributeCode => $attributeValue) {
                        if ($attributeValue) {
                            $model->setData($attributeCode, null);
                        }
                    }
                }

                $model = $this->bannerRepository->save($model);
                $params['entity_id'] = $model->getEntityId();
                $this->messageManager->addSuccessMessage(__('You saved the Banner.'));
                $this->dataPersistor->clear('evalent_banner_banner_entity');

                if ($this->getRequest()->getParam('back')) {
                    return $resultRedirect->setPath('*/*/edit', $params);
                }
                return $resultRedirect->setPath('*/*/');
            } catch (LocalizedException $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            } catch (Exception $e) {
                $this->messageManager->addExceptionMessage($e, __('Something went wrong while saving the Banner.'));
            }

            $this->dataPersistor->set('evalent_banner_banner_entity', $data);
            return $resultRedirect->setPath('*/*/edit', $params);
        }
        return $resultRedirect->setPath('*/*/');
    }
}
