<?php

namespace Evalent\MatrixRateMods\Model;

class Config implements \Evalent\MatrixRateMods\Api\ConfigInterface
{
    /** @var \Magento\Framework\App\Config\ScopeConfigInterface */
    private $scopeConfig;

    /**
     * Config constructor.
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     */
    public function __construct(\Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig){
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @inheirtDoc
     */
    public function forceWeight(): ?bool{
        return $this->scopeConfig->isSetFlag('carriers/matrixrate/force_weight',\Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }
}