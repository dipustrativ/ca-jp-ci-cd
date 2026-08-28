<?php


namespace CarpetAvenue\Productify\EnhanceCategory\Rewrite\Magento\Catalog\Model;

use CarpetAvenue\Productify\EnhanceCategory\Helper\Data;

class Category
{

    /**
     * @var \CarpetAvenue\Productify\EnhanceCategory\Helper\Data
     */
    private $dataHelper;

    public function __construct(
        Data $dataHelper
    ) {
        $this->dataHelper = $dataHelper;
    }

    public function before__call(\Magento\Catalog\Model\Category $subject, $method, $args = null)
    {
        if ($method == 'setPostedProducts') {
            $products = $args[0];
            $defaultPosition = $this->dataHelper->getDefaultCatalogProductPosition();
            foreach ($products as $productId => $position) {
                if ($position == "") {
                    $products[$productId] = $defaultPosition;
                }
            }
            $args[0] = $products;
        }
        return [$method, $args];
    }
}
