<?php

namespace CarpetAvenue\Productify\Controller\Adminhtml\Campaign;

use CarpetAvenue\Productify\Helper\CampaignHelper;
use Exception;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;

class Import extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context                  $context,
        protected CampaignHelper $campaignHelper
    )
    {
        parent::__construct($context);
    }


    public function execute()
    {
        try {
            $this->campaignHelper->importDynamicCampaign();
            $this->messageManager->addSuccessMessage("Campaign imported successfully");
        } catch (Exception $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        }
        //-- return back
        return $this->_redirect($this->_redirect->getRefererUrl());
    }

}