<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\Helper;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

/**
 * Related products carousel config helper.
 * Merged from Evalent_RelatedCarouselProducts (reference project).
 */
class RelatedCarouselData extends AbstractHelper
{
    const ENABLED_XML_PATH = 'catalog/related_carousel_products/enabled';
    const ENABLED_REQUEST_PARAM = 'catalog/related_carousel_products/enabled_request_param';
    const REQUEST_PARAM_NAME = 'catalog/related_carousel_products/request_param_name';
    const REQUEST_PARAM_VALUE = 'catalog/related_carousel_products/request_param_value';
    const MAIN_ATTRIBUTE_XML_PATH = 'catalog/related_carousel_products/attribute_1';
    const SECONDARY_ATTRIBUTE_XML_PATH = 'catalog/related_carousel_products/attribute_2';
    const LIST_SIZE_XML_PATH = 'catalog/related_carousel_products/list_size';
    const UNDERLINE_COLOR = 'catalog/related_carousel_products/underline_color';
    const PRICE_COLOR = 'catalog/related_carousel_products/price_color';
    const PRODUCT_NAME_COLOR = 'catalog/related_carousel_products/product_name_color';

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::ENABLED_XML_PATH, ScopeInterface::SCOPE_STORE);
    }

    public function isEnabledRequestParam(): bool
    {
        return $this->scopeConfig->isSetFlag(self::ENABLED_REQUEST_PARAM, ScopeInterface::SCOPE_STORE);
    }

    public function getRequestParamName(): string
    {
        return $this->scopeConfig->getValue(self::REQUEST_PARAM_NAME, ScopeInterface::SCOPE_STORE) ?? "";
    }

    public function getRequestParamValue(): string
    {
        return $this->scopeConfig->getValue(self::REQUEST_PARAM_VALUE, ScopeInterface::SCOPE_STORE) ?? "";
    }

    public function getMainAttribute(): string
    {
        return $this->scopeConfig->getValue(self::MAIN_ATTRIBUTE_XML_PATH, ScopeInterface::SCOPE_STORE) ?? "";
    }

    public function getSecondaryAttribute(): string
    {
        return $this->scopeConfig->getValue(self::SECONDARY_ATTRIBUTE_XML_PATH, ScopeInterface::SCOPE_STORE) ?? "";
    }

    public function getListSize(): int
    {
        return (int)$this->scopeConfig->getValue(self::LIST_SIZE_XML_PATH, ScopeInterface::SCOPE_STORE) - 1;
    }

    public function getUnderlineColor(): ?string
    {
        return $this->scopeConfig->getValue(self::UNDERLINE_COLOR, ScopeInterface::SCOPE_STORE);
    }

    public function getPriceColor(): ?string
    {
        return $this->scopeConfig->getValue(self::PRICE_COLOR, ScopeInterface::SCOPE_STORE);
    }

    public function getProductNameColor(): ?string
    {
        return $this->scopeConfig->getValue(self::PRODUCT_NAME_COLOR, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Find the deepest category of a product.
     */
    public function getDeepestCategory(Product $product): ?\Magento\Catalog\Model\Category
    {
        $depth = 0;
        $deepestCategory = null;

        /** @var \Magento\Catalog\Model\Category $category */
        foreach ($product->getCategoryCollection() as $category) {
            if (($levels = substr_count($category->getPath(), "/")) > $depth) {
                $depth = $levels;
                $deepestCategory = $category;
            }
        }

        return $deepestCategory;
    }
}
