<?php

namespace Evalent\CatalogPriceHistory\Block\Product\Ajax;

class View extends \Magento\Catalog\Block\Product\View
{
    public function getProductId()
    {
        return $this->getProduct()->getId();
    }
}