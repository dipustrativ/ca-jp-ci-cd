<?php
namespace CarpetAvenue\Productify\ProductStatus\Plugin\Model;

use CarpetAvenue\Productify\ProductStatus\Api\ConfigInterface;
use Magento\Catalog\Model\Category as CategoryModel;

class Category{
    protected ConfigInterface $config;
    public function __construct(
        ConfigInterface $config
    ){
        $this->config = $config;
    }

    public function afterGetProductCollection(CategoryModel $subject, $result)
    {
        if ($this->config->approveEnabled()){
            $result->addAttributeToFilter('approved', \Magento\Eav\Model\Entity\Attribute\Source\Boolean::VALUE_YES);
        }
        if ($this->config->onlineEnabled()){
            $result->addAttributeToFilter('online', \Magento\Eav\Model\Entity\Attribute\Source\Boolean::VALUE_YES);
        }
        if ($this->config->weightEnabled()){
            $result->addAttributeToFilter('weight', ['gt' => 0]);
        }
        return $result;
    }
}