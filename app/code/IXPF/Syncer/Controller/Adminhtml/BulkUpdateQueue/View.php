<?php
declare(strict_types=1);

namespace IXPF\Syncer\Controller\Adminhtml\BulkUpdateQueue;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use IXPF\Syncer\Model\IxpfProductBulkUpdateQueueFactory;
use Magento\Framework\Registry;

class View extends Action
{
    const ADMIN_RESOURCE = 'IXPF_Syncer::bulk_update_revert';

    public function __construct(
        Context $context,
        protected PageFactory $pageFactory,
        protected IxpfProductBulkUpdateQueueFactory $queueFactory,
        protected Registry $registry
    )
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = $this->getRequest()->getParam('id');
        $model = $this->queueFactory->create();

        if ($id) {
            $model->load($id);
            if (!$model->getId()) {
                $this->messageManager->addErrorMessage(__('This record no longer exists.'));
                $resultRedirect = $this->resultRedirectFactory->create();
                return $resultRedirect->setPath('*/*/');
            }
        }

        $this->registry->register('bulk_update_queue', $model);

        $page = $this->pageFactory->create();
        $page->setActiveMenu("IXPF_Syncer::bulk_update_revert");
        $page->getConfig()->getTitle()->prepend(__("Bulk Update Queue Details #%1", $id));
        return $page;
    }
}

