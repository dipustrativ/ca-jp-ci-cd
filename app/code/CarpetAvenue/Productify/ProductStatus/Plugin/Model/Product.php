<?php
namespace CarpetAvenue\Productify\ProductStatus\Plugin\Model;

use CarpetAvenue\Productify\ProductStatus\Model\StatusManager;
use Magento\Catalog\Model\Product as ProductModel;

class Product{
    protected StatusManager $statusManager;

    public function __construct(
        StatusManager $statusManager
    ){
        $this->statusManager = $statusManager;
    }

    public function afterGetStatus(ProductModel $subject, $result){
        try{
            if ($this->statusManager->isVisibleOnFrontend($subject)){
                return \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED;
            }
            return \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_DISABLED;
        }
        catch(\Exception $e){
            return $result;
        }
    }
}