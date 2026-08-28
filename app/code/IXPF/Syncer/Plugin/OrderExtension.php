<?php

declare(strict_types=1);

namespace IXPF\Syncer\Plugin;

use Magento\Sales\Api\Data\OrderExtensionFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;

class OrderExtension
{
    protected OrderExtensionFactory $extensionFactory;

    public function __construct(
        OrderExtensionFactory $extensionFactory
    ) {
        $this->extensionFactory = $extensionFactory;
    }

    public function afterGet(OrderRepositoryInterface $subject, OrderInterface $order): OrderInterface
    {
        return $this->addExtensionAttributes($order);
    }

    public function afterGetList(
        OrderRepositoryInterface $subject,
        OrderSearchResultInterface $searchResult
    ): OrderSearchResultInterface {
        foreach ($searchResult->getItems() as $order) {
            $this->addExtensionAttributes($order);
        }

        return $searchResult;
    }

    protected function addExtensionAttributes(OrderInterface $order): OrderInterface
    {
        $extensionAttributes = $order->getExtensionAttributes();
        if (!$extensionAttributes) {
            $extensionAttributes = $this->extensionFactory->create();
        }

        $extensionAttributes->setIsPosOrder($order->getData('is_pos_order'));
        $extensionAttributes->setStoreToEurRate($order->getData('store_to_eur_rate'));
        $order->setExtensionAttributes($extensionAttributes);

        return $order;
    }
}
