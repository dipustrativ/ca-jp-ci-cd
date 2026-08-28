<?php


namespace Evalent\ProductCertificate\Model;


use Evalent\ProductCertificate\Api\ConfigInterface;
use Magento\Framework\Phrase;

class Config implements ConfigInterface
{
    /** @var \Magento\Framework\App\Config\ScopeConfigInterface */
    private $scopeConfig;

    private $validProductionTypes = null;

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
    public function isValidProductionType($id, $storeId): bool
    {
        if (null === $id){
            return false;
        }
        if (0 === strlen($id)){
            return false;
        }
        if (null === $this->validProductionTypes){
            $this->validProductionTypes = explode(',', $this->scopeConfig->getValue(ConfigInterface::SALES_PDF_COA_PRODUCTION_TYPE,\Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId));
        }
        return in_array($id,$this->validProductionTypes);
    }

    /**
     * @inheirtDoc
     */
    public function getRowPriceLimit($storeId): float
    {
        return $this->scopeConfig->getValue(ConfigInterface::SALES_PDF_COA_ROW_PRICE_LIMIT,\Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @inheirtDoc
     */
    public function includeSignature($storeId): bool
    {
        return "1" === $this->scopeConfig->getValue(ConfigInterface::SALES_PDF_COA_INCLUDE_SIGNATURE,\Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @inheirtDoc
     */
    public function getFooterText($storeId): ?string
    {
        if ($footerText = $this->scopeConfig->getValue(ConfigInterface::SALES_PDF_COA_FOOTER_TEXT,\Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId)){
            if (0 < strlen($footerText)){
                return new Phrase($footerText);
            }
        }
        return "Carpient GmbH, Bargkoppelweg 64, 22145 ". __('Hamburg, Germany');
    }
}
