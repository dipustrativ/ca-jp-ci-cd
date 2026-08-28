<?php

namespace IXPF\Syncer\Model\ResourceModel\IxpfPurchaseOrderProduct\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

class Collection extends SearchResult
{
    protected function _initSelect()
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $_request = $objectManager->get('Magento\Framework\App\RequestInterface');
        $purchaseId = $_request->getParam('ixpf_purchase_id');

        parent::_initSelect();
        $this->getSelect()
            ->where('main_table.ixpf_purchase_id = ?', $purchaseId);
        return $this;
    }
}