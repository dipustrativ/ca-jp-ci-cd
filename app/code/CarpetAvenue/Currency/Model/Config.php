<?php
namespace CarpetAvenue\Currency\Model;

use Magento\Store\Model\ScopeInterface;

class Config
{
    public function __construct(protected \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig){
        $this->scopeConfig = $scopeConfig;
    }

    public function enabled(): bool{
        return 1 == $this->scopeConfig->getValue('catalog/price/ceil_to_zeroes/enable', ScopeInterface::SCOPE_STORE);
    }
    public function priceThreshold(): int
    {
        return $this->scopeConfig->getValue('catalog/price/ceil_to_zeroes/price_threshold', ScopeInterface::SCOPE_STORE);
    }
    public function numberOfZeroes(): int
    {
        return $this->scopeConfig->getValue('catalog/price/ceil_to_zeroes/number_of_zeroes', ScopeInterface::SCOPE_STORE);
    }
    public function maxPercent(): int
    {
        return $this->scopeConfig->getValue('catalog/price/ceil_to_zeroes/max_percent', ScopeInterface::SCOPE_STORE);
    }
}