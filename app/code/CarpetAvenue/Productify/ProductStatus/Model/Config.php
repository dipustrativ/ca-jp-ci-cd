<?php
namespace CarpetAvenue\Productify\ProductStatus\Model;

class Config implements \CarpetAvenue\Productify\ProductStatus\Api\ConfigInterface {
    private \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig;

    /**
     * Config constructor.
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     */
    public function __construct(\Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig){
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @inheritDoc
     */
    public function onlineEnabled(): bool{
        return 1 == $this->scopeConfig->isSetFlag('productify/product_status/online_enabled');
    }

    /**
     * @inheritDoc
     */
    public function approveEnabled():bool{
        return 1 == $this->scopeConfig->isSetFlag('productify/product_status/approve_enabled');
    }

    /**
     * @inheritDoc
     */
    public function weightEnabled():bool{
        return 1 == $this->scopeConfig->isSetFlag('productify/product_status/weight_enabled');
    }
}