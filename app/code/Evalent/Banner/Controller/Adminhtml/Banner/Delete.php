<?php

declare(strict_types=1);

namespace Evalent\Banner\Controller\Adminhtml\Banner;

use Evalent\Banner\Api\BannerRepositoryInterface;
use Evalent\Banner\Controller\Adminhtml\Banner;
use Exception;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Registry;

class Delete extends Banner
{

    /**
     * @var \Evalent\Banner\Api\BannerRepositoryInterface
     */
    private $bannerRepository;

    public const ADMIN_RESOURCE = 'Evalent_Banner::Banner_delete';

    /**
     * Delete constructor.
     *
     * @param \Evalent\Banner\Api\BannerRepositoryInterface $bannerRepository
     * @param \Magento\Backend\App\Action\Context           $context
     * @param \Magento\Framework\Registry                   $coreRegistry
     */
    public function __construct(
        BannerRepositoryInterface $bannerRepository,
        Context $context,
        Registry $coreRegistry
    ) {
        parent::__construct($context, $coreRegistry);
        $this->bannerRepository = $bannerRepository;
    }

    /**
     * Delete action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        /** @var \Magento\Backend\Model\View\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();
        $id = $this->getRequest()->getParam('entity_id');
        if ($id) {
            try {
                $this->bannerRepository->deleteById($id);
                $this->messageManager->addSuccessMessage(__('You deleted the Banner.'));
                return $resultRedirect->setPath('*/*/');
            } catch (Exception $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
                return $resultRedirect->setPath('*/*/edit', ['entity_id' => $id]);
            }
        }
        $this->messageManager->addErrorMessage(__('We can\'t find a Banner to delete.'));
        return $resultRedirect->setPath('*/*/');
    }
}
