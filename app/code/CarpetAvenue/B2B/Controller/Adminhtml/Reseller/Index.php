<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Reseller applications admin grid page.
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Controller\Adminhtml\Reseller;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_B2B::reseller_applications';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('CarpetAvenue_B2B::reseller_applications');
        $page->getConfig()->getTitle()->prepend(__('Reseller Applications'));

        return $page;
    }
}
