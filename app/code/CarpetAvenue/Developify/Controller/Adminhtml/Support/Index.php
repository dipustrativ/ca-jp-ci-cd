<?php

namespace CarpetAvenue\Developify\Controller\Adminhtml\Support;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;

class Index extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context
    )
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultPage = $this->resultFactory->create(
            \Magento\Framework\Controller\ResultFactory::TYPE_PAGE
        );
        $resultPage->getConfig()->getTitle()->prepend(__('Support'));

        return $resultPage;
    }
}