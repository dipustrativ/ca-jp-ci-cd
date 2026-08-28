<?php

namespace IXPF\Syncer\Plugin;

use Magento\Catalog\Api\ProductRepositoryInterface;

class OrderItemDefaultName
{
    public function __construct(
        private ProductRepositoryInterface $productRepository
    ) {
    }

    public function afterGetItems(\Magento\Sales\Api\Data\OrderInterface $subject, $result)
    {
        foreach ($result as $key => $item) {
            try {
                $product = $this->productRepository->get($item->getSku());
                $extAttrs = $item->getExtensionAttributes();
                if (!$extAttrs) {
                    $extAttrs = \Magento\Framework\App\ObjectManager::getInstance()
                        ->create(\Magento\Sales\Api\Data\OrderItemExtensionInterface::class);
                }

                $extAttrs->setDefaultName($product->getName());
                $item->setExtensionAttributes($extAttrs);
            } catch (\Exception $e) {
                $extAttrs = $item->getExtensionAttributes();
                if (!$extAttrs) {
                    $extAttrs = \Magento\Framework\App\ObjectManager::getInstance()
                        ->create(\Magento\Sales\Api\Data\OrderItemExtensionInterface::class);
                }
                $extAttrs->setDefaultName($item->getName());
                $item->setExtensionAttributes($extAttrs);
            }
        }
        return $result;
    }
}