<?php

namespace IXPF\Syncer\Controller\Adminhtml\BulkUpdateQueue;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    const ADMIN_RESOURCE = 'IXPF_Syncer::bulk_update_revert';

    public function __construct(
        Context $context,
        protected PageFactory $pageFactory
    )
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu("IXPF_Syncer::bulk_update_revert");
        $page->getConfig()->getTitle()->prepend(__("Bulk Update Revert"));
        return $page;
    }
}

