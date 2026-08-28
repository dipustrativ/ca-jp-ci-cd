<?php

namespace IXPF\Syncer\Controller\Adminhtml\Webhook;

use Magento\Backend\App\Action;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

class View extends Action
{
    /**
     * Constructor
     *
     * @param Action\Context $context
     * @param PageFactory $resultPageFactory
     * @param JsonFactory $jsonFactory
     */
    public function __construct(
        Action\Context $context,
        protected PageFactory $resultPageFactory,
        protected JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Execute method
     *
     * @return ResponseInterface|ResultInterface
     */
    public function execute()
    {
        $webhookId = (int)$this->getRequest()->getParam('entity_id');
        if (!$webhookId) {
            $this->messageManager->addErrorMessage(__('Invalid webhook ID.'));
            return $this->_redirect('ixpfsyncer/webhook/index');
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu("Magento_Backend::system");
        $resultPage->getConfig()->getTitle()->prepend(__("IXPF Webhook View"));
        return $resultPage;
    }

}