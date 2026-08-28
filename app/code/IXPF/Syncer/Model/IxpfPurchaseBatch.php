<?php

namespace IXPF\Syncer\Model;

use Magento\Framework\Model\AbstractModel;

class IxpfPurchaseBatch extends AbstractModel
{
    const TABLE = 'ixpf_purchases_batch';
    const ENTITY = 'entity_id';

    const PENDING_STATUS = 'pending';
    const PROCESSING_STATUS = 'processing';
    const PROCESSING_COMPLETE_FROM_MAGENTO_STATUS = 'processing_complete_from_magento';
    const PROCESSING_FAILED_STATUS = 'processing_failed';
    const FAILED_FROM_IXPF_STATUS = 'failed_from_ixpf';
    const FAILED_FROM_MAGENTO_STATUS = 'failed_from_magento';
    const FAILED_STATUS = 'failed';
    const COMPLETED_STATUS = 'completed';
    const CLOSED_STATUS = 'closed';

    public function _construct()
    {
        $this->_init("IXPF\Syncer\Model\ResourceModel\IxpfPurchaseBatch");
    }

    public function setProductsData(array $data): void
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $json = $objectManager->create('Magento\Framework\Serialize\SerializerInterface');
        $this->setData('products', $json->serialize($data));
    }

    public function getProductsData(): array
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $json = $objectManager->create('Magento\Framework\Serialize\SerializerInterface');
        $products = $this->getData('products');
        return $products ? $json->unserialize($products) : [];
    }

}