<?php

declare(strict_types=1);

namespace IXPF\Syncer\Controller\Adminhtml\Review;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'IXPF_Syncer::product_reviews';

    public function __construct(
        Context $context,
        protected PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Magento_Backend::system');
        $page->getConfig()->getTitle()->prepend(__('IXPF Product Reviews'));

        return $page;
    }
}
