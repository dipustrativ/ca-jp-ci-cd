<?php


namespace CarpetAvenue\Productify\ProductStatus\Api;

use \Magento\Framework\Model\AbstractModel;


interface StatusManagerInterface
{
    /**
     * @param AbstractModel $product
     * @return bool
     */
    public function isVisibleOnFrontend(AbstractModel $product): bool;
}