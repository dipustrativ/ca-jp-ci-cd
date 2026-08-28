<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Developify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Developify\Controller\Adminhtml\Cron;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Read-only view of every registered cron job. Config-backed, no DB access.
 */
class Jobs extends Action implements HttpGetActionInterface
{
    /**
     * Constructor
     *
     * @param Action\Context $context
     * @param PageFactory $resultPageFactory
     */
    public function __construct(
        Action\Context $context,
        protected PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Render the cron jobs page
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        // In the adminhtml area Magento_Backend's di.xml rewires this factory's
        // instanceName to the backend page, which is what carries setActiveMenu().
        /** @var \Magento\Backend\Model\View\Result\Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('CarpetAvenue_Developify::cron_jobs');
        $resultPage->getConfig()->getTitle()->prepend(__('Cron Jobs'));

        return $resultPage;
    }

    /**
     * Check if the user has permission to access this controller
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('CarpetAvenue_Developify::cron_jobs');
    }
}
