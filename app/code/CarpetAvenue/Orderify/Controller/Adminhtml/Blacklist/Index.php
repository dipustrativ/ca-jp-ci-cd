<?php

namespace CarpetAvenue\Orderify\Controller\Adminhtml\Blacklist;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{

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
        $page->setActiveMenu("Magento_Sales::sales");
        $page->getConfig()->getTitle()->prepend(__("Address / Email / IP Blacklist"));
        return $page;
    }
}