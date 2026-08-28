<?php

namespace IXPF\Syncer\Controller\Adminhtml\Webhook;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    const ADMIN_RESOURCE = 'IXPF_Syncer::webhook_list';

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
        $page->setActiveMenu("Magento_Backend::system");
        $page->getConfig()->getTitle()->prepend(__("IXPF Webhook List"));
        return $page;
    }
}