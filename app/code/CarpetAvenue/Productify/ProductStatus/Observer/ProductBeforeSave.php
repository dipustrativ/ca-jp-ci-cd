<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\ProductStatus\Observer;

use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use CarpetAvenue\Productify\ProductStatus\Api\ConfigInterface;
use Magento\Framework\App\Request\Http;

class ProductBeforeSave implements ObserverInterface
{

    /**
     * @var ManagerInterface
     */
    protected ManagerInterface $messageManager;

    protected ConfigInterface $config;

    protected Product $product;

    protected Http $request;

    public function __construct(
        ManagerInterface $messageManager,
        ConfigInterface $config,
        Http $request
    ) {
        $this->messageManager = $messageManager;
        $this->config = $config;
        $this->request = $request;
    }

    /**
     * @param Observer $observer
     *
     * @return void
     */
    public function execute(Observer $observer): void
    {
        /** @var Product $product */
        $this->product = $observer->getEvent()->getProduct();


        if ($this->product->getStoreId() !== 0) {
            return;
        }
        if ($this->config->weightEnabled()){
            if ('advancedimportexport' == $this->request->getRouteName()){
                return;
            }
            $this->disableProductWithNoWeight();
        }
    }

    private function disableProductWithNoWeight()
    {
        // See if product should be disabled due to no weight
        $weight = $this->product->getWeight();
        if (null == $weight | "0" == $weight) {
            $this->messageManager->addNoticeMessage(__("Weight is missing or 0. Product is disabled"));
            $this->product->setStatus(2);
        }
    }

}
