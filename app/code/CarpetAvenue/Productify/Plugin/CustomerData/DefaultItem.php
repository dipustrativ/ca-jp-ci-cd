<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\Plugin\CustomerData;

use Closure;
use CarpetAvenue\Productify\Helper\ProductionType;
use Magento\Quote\Model\Quote\Item;

class DefaultItem
{

    /**
     * @var \CarpetAvenue\Productify\Helper\ProductionType
     */
    private $productionTypeHelper;

    public function __construct(
        ProductionType $productionTypeHelper
    ) {
        $this->productionTypeHelper = $productionTypeHelper;
    }

    public function aroundGetItemData(\Magento\Checkout\CustomerData\DefaultItem $subject, Closure $proceed, Item $item)
    {
        $data = $proceed($item);
        $product = $item->getProduct();

        $additionalAttribute = [
            "unique" => $this->productionTypeHelper->isProductUnique($product)
        ];

        return array_merge($data, $additionalAttribute);
    }
}
