<?php

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Controller\Adminhtml\Region;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Handles both INSERT (new) and UPDATE (edit) for directory_country_region.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_Utilities::region_manager';

    public function __construct(
        Context $context,
        protected ResourceConnection $resourceConnection
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $data     = $this->getRequest()->getPostValue();
        $regionId = isset($data['region_id']) && $data['region_id'] !== '' ? (int)$data['region_id'] : null;

        $countryId   = trim($data['country_id'] ?? '');
        $code        = trim($data['code'] ?? '');
        $defaultName = trim($data['default_name'] ?? '');

        if (!$countryId || !$code || !$defaultName) {
            $this->messageManager->addErrorMessage(__('Country, Code and Default Name are all required.'));
            return $regionId
                ? $this->_redirect('*/*/edit', ['region_id' => $regionId])
                : $this->_redirect('*/*/new');
        }

        $connection = $this->resourceConnection->getConnection();
        $table      = $this->resourceConnection->getTableName('directory_country_region');

        $rowData = [
            'country_id'   => $countryId,
            'code'         => $code,
            'default_name' => $defaultName,
        ];

        try {
            if ($regionId) {
                $connection->update($table, $rowData, ['region_id = ?' => $regionId]);
                $this->messageManager->addSuccessMessage(__('Region "%1" has been updated.', $defaultName));
            } else {
                $connection->insert($table, $rowData);
                $newId = (int)$connection->lastInsertId($table);

                // Also insert a default en_US locale name
                $nameTable = $this->resourceConnection->getTableName('directory_country_region_name');
                $connection->insertOnDuplicate($nameTable, [
                    'locale'    => 'en_US',
                    'region_id' => $newId,
                    'name'      => $defaultName,
                ]);

                $this->messageManager->addSuccessMessage(__('Region "%1" has been added.', $defaultName));
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Error saving region: %1', $e->getMessage()));
            return $regionId
                ? $this->_redirect('*/*/edit', ['region_id' => $regionId])
                : $this->_redirect('*/*/new');
        }

        return $this->_redirect('*/*/index');
    }
}

