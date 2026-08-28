<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\EnhanceCategory\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\AbstractHelper;

class Data extends AbstractHelper
{

    const PRODUCT_CATEGORY_LINK_POSITION_XML_PATH = 'productify/enhance_category/product_category_link_position';

    /**
     * @param \Magento\Framework\App\Helper\Context $context
     */
    public function __construct(
        \Magento\Framework\App\Helper\Context $context
    ) {
        parent::__construct($context);
    }

    public function getDefaultCatalogProductPosition(): int
    {
        return (int)$this->scopeConfig->getValue(self::PRODUCT_CATEGORY_LINK_POSITION_XML_PATH);
    }
}
