<?php

namespace CarpetAvenue\Productify\Block\Product\List;

//use Evalent\DynamicSimilarProducts\Helper\Data;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;

class SimilarProductBlock extends Template
{
    public function __construct(
        Template\Context   $context,
        protected Registry $_registry,
//        protected Data $dataHelper,
        array $data = []
    )
    {
        parent::__construct($context, $data);
    }
    public function getEnableSimilarProductCatalogSearchApi($storeId = null): bool
    {
        return (bool) $this->_scopeConfig->getValue(
            'productify/similar_product/enabled',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        ) == 1;
    }

    public function getProduct()
    {
        return $this->_registry->registry('current_product');
    }

    public function getListSize()
    {
//        return $this->dataHelper->getListSize();
        return 10;
    }

    public function getSearchWidthHigh()
    {
//        return 1 + $this->dataHelper->getSecondaryAttributeWidth() / 100;
    }

    public function getSearchWidthLow()
    {
//        return 1 - $this->dataHelper->getSecondaryAttributeWidth() / 100;
    }
}
