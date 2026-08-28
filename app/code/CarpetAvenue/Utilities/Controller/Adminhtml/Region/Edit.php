<?php

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Controller\Adminhtml\Region;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Result\PageFactory;

/**
 * Renders the "Edit Region" form.
 */
class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_Utilities::region_manager';

    public function __construct(
        Context $context,
        protected PageFactory $pageFactory,
        protected ResourceConnection $resourceConnection
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $regionId = (int)$this->getRequest()->getParam('region_id');

        if ($regionId) {
            $connection = $this->resourceConnection->getConnection();
            $table      = $this->resourceConnection->getTableName('directory_country_region');
            $row        = $connection->fetchRow(
                $connection->select()->from($table)->where('region_id = ?', $regionId)
            );

            if (!$row) {
                $this->messageManager->addErrorMessage(__('Region with ID "%1" does not exist.', $regionId));
                return $this->_redirect('*/*/index');
            }
        }

        $page = $this->pageFactory->create();
        $page->setActiveMenu('CarpetAvenue_Utilities::region_manager');
        $page->getConfig()->getTitle()->prepend(__('Region Manager'));
        $page->getConfig()->getTitle()->prepend($regionId ? __('Edit Region') : __('Add New Region'));
        return $page;
    }
}

