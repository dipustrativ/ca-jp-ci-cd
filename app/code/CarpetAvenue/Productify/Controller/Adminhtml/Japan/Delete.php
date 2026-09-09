<?php

namespace CarpetAvenue\Productify\Controller\Adminhtml\Japan;

use CarpetAvenue\Productify\Model\Blacklist\CachePurger;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Result\PageFactory;

class Delete extends Action implements HttpGetActionInterface
{

    public function __construct(
        Context                      $context,
        protected ResourceConnection $resourceConnection,
        private readonly CachePurger $cachePurger
    )
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $sku = $this->getRequest()->getParam('sku');
        if ($sku) {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('ca_jp_blacklist');
            $connection->delete($tableName, ['sku = ?' => $sku]);
            $this->cachePurger->purgeBySku((string) $sku);
        }
        $this->messageManager->addSuccessMessage(__('The SKU has been removed from the JP blocklist.'));
        return $this->_redirect('productify/japan/blacklist');
    }
}