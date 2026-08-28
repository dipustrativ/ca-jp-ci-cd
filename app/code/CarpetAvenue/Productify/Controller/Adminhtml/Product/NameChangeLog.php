<?php

namespace CarpetAvenue\Productify\Controller\Adminhtml\Product;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class NameChangeLog extends Action
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
        $page->setActiveMenu("Magento_Backend::system");
        $page->getConfig()->getTitle()->prepend(__("Product Name Change Log"));
        return $page;
    }
}