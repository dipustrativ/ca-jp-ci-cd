<?php
namespace CarpetAvenue\Productify\ProductStatus\Plugin\Model\ResourceModel\Product;

use CarpetAvenue\Productify\ProductStatus\Api\ConfigInterface;
class Collection{
    protected ConfigInterface $config;
    public function __construct(
        ConfigInterface $config
    ){
        $this->config = $config;
    }

    public function afterSetVisibility(\Magento\Catalog\Model\ResourceModel\Product\Collection $subject, $result){
        if ($this->config->approveEnabled()){
            $subject->addAttributeToFilter('approved', \Magento\Eav\Model\Entity\Attribute\Source\Boolean::VALUE_YES);
        }
        if ($this->config->onlineEnabled()){
            $subject->addAttributeToFilter('online', \Magento\Eav\Model\Entity\Attribute\Source\Boolean::VALUE_YES);
        }
        if ($this->config->weightEnabled()){
            $subject->addAttributeToFilter('weight', ['gt' => 0]);
        }
        return $result;
    }
}