<?php

namespace IXPF\Syncer\Controller\Adminhtml\Webhook;

use IXPF\Syncer\Helper\Webhook;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;

class ManualTrigger extends Action implements HttpGetActionInterface
{
    protected $webhookHelper;

    public function __construct(
        Context $context,
        Webhook $webhookHelper
    )
    {
        parent::__construct($context);
        $this->webhookHelper = $webhookHelper;
    }

    public function execute()
    {
        try {
            $this->webhookHelper->run("product", "manual trigger");
            $this->messageManager->addSuccessMessage(__("Webhook triggered successfully."));
        } catch (\Exception $exception) {
            $this->messageManager->addExceptionMessage($exception);
        }
        $redirect = $this->resultRedirectFactory->create();
        return $redirect->setUrl($this->_redirect->getRedirectUrl());
    }
}
