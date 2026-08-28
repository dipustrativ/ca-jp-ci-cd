<?php

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Controller\Adminhtml\Region;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Renders the empty "Add New Region" form.
 */
class NewAction extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_Utilities::region_manager';

    public function __construct(
        Context $context,
        protected PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('CarpetAvenue_Utilities::region_manager');
        $page->getConfig()->getTitle()->prepend(__('Region Manager'));
        $page->getConfig()->getTitle()->prepend(__('Add New Region'));
        return $page;
    }
}

