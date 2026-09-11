<?php
namespace Evalent\OrderMods\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

class Config implements \Evalent\OrderMods\Api\ConfigInterface{
    private ScopeConfigInterface $scopeConfig;

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
    public function denyHoldForOrderStatus($status): ?bool{
        try{
            if($denyStatuses = $this->scopeConfig->getValue('sales/adminorder/deny_hold_for_order_status')){
                if(in_array($status, explode(',',$denyStatuses))){
                    return true;
                }
            }
            return false;
        }
        catch(\Exception $e){
            return false;
        }        
    }
}