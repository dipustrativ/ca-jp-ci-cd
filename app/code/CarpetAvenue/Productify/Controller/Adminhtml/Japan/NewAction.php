<?php

namespace CarpetAvenue\Productify\Controller\Adminhtml\Japan;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

class NewAction extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        protected PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu("Magento_Catalog::catalog");
        $page->getConfig()->getTitle()->prepend(__("Add New SKU to JP blocklist"));
        return $page;
    }
}

