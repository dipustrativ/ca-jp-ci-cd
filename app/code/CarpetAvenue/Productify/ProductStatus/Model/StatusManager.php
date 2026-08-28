<?php
namespace CarpetAvenue\Productify\ProductStatus\Model;

use CarpetAvenue\Productify\ProductStatus\Api\ConfigInterface;
use Magento\Framework\Model\AbstractModel;

class StatusManager implements \CarpetAvenue\Productify\ProductStatus\Api\StatusManagerInterface{
    protected ConfigInterface $config;
    public function __construct(
        ConfigInterface $config
    ){
        $this->config = $config;
    }

    private function isOnline(AbstractModel $product): bool{
        if ($this->config->onlineEnabled()){
            $online = $product->getData('online');
            if(null !== $online){
                if (0 == $online){
                    return false;
                }
            }
        }
        return true;
    }
    private function isApproved(AbstractModel $product): bool{
        if ($this->config->approveEnabled()){
            $approved = $product->getData('approved');
            if(null !== $approved){
                if (0 == $approved){
                    return false;
                }
            }
        }
        return true;
    }
    private function hasValidWeight(AbstractModel $product): bool{
        if ($this->config->weightEnabled()){
            $weight = $product->getData('weight');
            if(null !== $weight){
                if (0 < $weight){
                    return true;
                }
                return false;
            }
            return false;
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function isVisibleOnFrontend(AbstractModel $product): bool{
        return (
            \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED == $product->getData('status')
            && $this->isOnline($product)
            && $this->isApproved($product)
            && $this->hasValidWeight($product)
        );
    }
}