<?php


namespace Evalent\CatalogPriceHistory\Model;


class Config implements \Evalent\CatalogPriceHistory\Api\ConfigInterface
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
     * @inheritDoc
     */
    public function isFrontendEnabled($storeId): bool{
        return 1 == $this->scopeConfig->getValue('sales/omnibus/frontend_enabled',\Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getLowestPriceDays($storeId): int{
        return $this->scopeConfig->getValue('sales/omnibus/lowest_price_days',\Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @inheirtDoc
     */
    public function isCronEnabled(): bool{
        return 1 == $this->scopeConfig->getValue('sales/omnibus/cron_enabled');
    }
    
    /*
     * @inheritDoc
     */
    public function getIgnoreForSkus(): array{
        $skus = $this->scopeConfig->getValue('sales/omnibus/ignore_skus');
        if (empty($skus)) {
            return [];
        }
        return explode(',', $skus);
    }
}