<?php

namespace CarpetAvenue\Productify\Controller\Adminhtml\Japan;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

class Save extends Action implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        protected ResourceConnection $resourceConnection
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $data = $this->getRequest()->getPostValue();
        $sku = $data['sku'] ?? null;
        if ($sku) {
            try {
                $connection = $this->resourceConnection->getConnection();
                $tableName = $this->resourceConnection->getTableName('ca_jp_blacklist');
                $connection->insert($tableName, ['sku' => $sku]);
                $this->messageManager->addSuccessMessage(__('SKU "%1" has been added to JP blocklist.', $sku));
            } catch (\Exception $e) {
                $this->messageManager->addErrorMessage(__('Error adding SKU: %1', $e->getMessage()));
            }
        } else {
            $this->messageManager->addErrorMessage(__('SKU is required.'));
        }
        return $this->_redirect('productify/japan/blacklist');
    }
}

