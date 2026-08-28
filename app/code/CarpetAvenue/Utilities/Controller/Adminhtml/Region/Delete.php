<?php

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Controller\Adminhtml\Region;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;

/**
 * Deletes a region from directory_country_region (and its locale names).
 */
class Delete extends Action
{
//    public const ADMIN_RESOURCE = 'CarpetAvenue_Utilities::region_manager';

    public function __construct(
        Context $context,
        protected ResourceConnection $resourceConnection
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $regionId = (int)$this->getRequest()->getParam('region_id');
        if (!$regionId) {
            $this->messageManager->addErrorMessage(__('Region ID is required.'));
            return $this->_redirect('*/*/index');
        }


        try {
            $connection = $this->resourceConnection->getConnection();
            // Delete locale name entries first (FK)
            $nameTable = $this->resourceConnection->getTableName('directory_country_region_name');
            $connection->delete($nameTable, ['region_id = ?' => $regionId]);

            // Delete the region itself
            $table = $this->resourceConnection->getTableName('directory_country_region');
            $connection->delete($table, ['region_id = ?' => $regionId]);

            $this->messageManager->addSuccessMessage(__('Region has been deleted successfully.'));
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Error deleting region: %1', $e->getMessage()));
        }

        return $this->_redirect('*/*/index');
    }
}

