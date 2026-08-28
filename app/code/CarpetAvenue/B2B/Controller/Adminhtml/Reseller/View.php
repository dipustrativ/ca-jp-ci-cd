<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Reseller application detail page.
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Controller\Adminhtml\Reseller;

use CarpetAvenue\B2B\Model\ResellerApplicationFactory;
use CarpetAvenue\B2B\Model\ResourceModel\ResellerApplication as ResellerApplicationResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;

class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_B2B::reseller_applications';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly ResellerApplicationFactory $applicationFactory,
        private readonly ResellerApplicationResource $applicationResource,
        private readonly Registry $registry
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $id = (int)$this->getRequest()->getParam('id');

        $application = $this->applicationFactory->create();
        $this->applicationResource->load($application, $id);

        if (!$application->getId()) {
            $this->messageManager->addErrorMessage(__('This reseller application no longer exists.'));
            /** @var \Magento\Backend\Model\View\Result\Redirect $redirect */
            $redirect = $this->resultRedirectFactory->create();
            return $redirect->setPath('*/*/index');
        }

        $this->registry->register('carpetavenue_b2b_reseller_application', $application);

        $page = $this->pageFactory->create();
        $page->setActiveMenu('CarpetAvenue_B2B::reseller_applications');
        $page->getConfig()->getTitle()->prepend(__('Application #%1', $application->getId()));

        return $page;
    }
}
