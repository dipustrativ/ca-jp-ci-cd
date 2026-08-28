<?php
declare(strict_types=1);

namespace IXPF\Syncer\Controller\Adminhtml\Docs;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    const ADMIN_RESOURCE = 'IXPF_Syncer::docs';

    public function __construct(
        Context $context,
        protected readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('IXPF_Syncer::docs');
        $page->getConfig()->getTitle()->prepend(__('IXPF API Docs'));
        return $page;
    }
}
