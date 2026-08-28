<?php
namespace CarpetAvenue\Proforma\Model;

class Config{
    public function __construct(private \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig){
    }
    public function enabled($storeId): bool{
        return 1 == $this->scopeConfig->isSetFlag('sales/ca_proforma/enabled',\Magento\Store\Model\ScopeInterface::SCOPE_STORE,$storeId);
    }
}