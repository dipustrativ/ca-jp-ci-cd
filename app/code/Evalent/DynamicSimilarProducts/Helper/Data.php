<?php

declare(strict_types=1);

namespace Evalent\DynamicSimilarProducts\Helper;

use Exception;
use Magento\Catalog\API\ProductRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Catalog\Model\Config;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Catalog\Block\Product\Context as ProductContext;

class Data extends AbstractHelper
{

    const ENABLED_XML_PATH = 'catalog/dynamic_related_products/enabled';
    const MAIN_ATTRIBUTE_XML_PATH = 'catalog/dynamic_related_products/attribute_1';
    const MAIN_ATTRIBUTE_BOOST_XML_PATH = 'catalog/dynamic_related_products/attribute_1_boost';
    const MAIN_ATTRIBUTE_CLAUSE_XML_PATH = 'catalog/dynamic_related_products/attribute_1_clause';
    const MAIN_ATTRIBUTE_IGNORE_XML_PATH = 'catalog/dynamic_related_products/attribute_1_ignore';
    const SECONDARY_ATTRIBUTE_XML_PATH = 'catalog/dynamic_related_products/attribute_2';
    const TERTIARY_ATTRIBUTE_XML_PATH = 'catalog/dynamic_related_products/attribute_3';
    const TERTIARY_ATTRIBUTE_BOOST_XML_PATH = 'catalog/dynamic_related_products/attribute_3_boost';
    const TERTIARY_ATTRIBUTE_CLAUSE_XML_PATH = 'catalog/dynamic_related_products/attribute_3_clause';
    const SECONDARY_ATTRIBUTE_WIDTH_XML_PATH = 'catalog/dynamic_related_products/attribute_2_width';
    const SORTBY_ATTRIBUTES_XML_PATH = 'catalog/dynamic_related_products/attributes_sort_by';
    const LIST_SIZE_XML_PATH = 'catalog/dynamic_related_products/list_size';
    const USE_PARENT_CATEGORY_THRESHOLD_XML_PATH = 'catalog/dynamic_related_products/use_parent_category_threshold';
    const BUTTON_FONT_SIZE = 'catalog/dynamic_related_products/button_font_size';
    const BUTTON_FONT_WEIGHT = 'catalog/dynamic_related_products/button_font_weight';
    const BUTTON_TEXT = 'catalog/dynamic_related_products/button_text';

    private $mainAttribute = null;
    private $secondaryAttribute = null;
    private $tertiaryAttribute = null;

    private $product = null;

    protected \Magento\Framework\Registry $coreRegistry;

    /**
     * @param Context $context
     */
    public function __construct(
        Context $context,
        protected AttributeRepositoryInterface $attributeRepository,
        protected ProductRepositoryInterface $productRepository,
        private Config $catalogConfig,
        protected UrlInterface $urlBuilder,
        ProductContext $productContext
    ) {
        parent::__construct($context);
        $this->coreRegistry = $productContext->getRegistry();
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::ENABLED_XML_PATH, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getMainAttribute(): string
    {
        return $this->scopeConfig->getValue(self::MAIN_ATTRIBUTE_XML_PATH, ScopeInterface::SCOPE_STORE) ?? "";
    }

    /**
     * @return float
     */
    public function getMainAttributeBoost(): float
    {
        return (float)$this->scopeConfig->getValue(self::MAIN_ATTRIBUTE_BOOST_XML_PATH, ScopeInterface::SCOPE_STORE) ?? 1.0;
    }
    /**
     * @return string
     */
    public function getMainAttributeClause(): string
    {
        return $this->scopeConfig->getValue(self::MAIN_ATTRIBUTE_CLAUSE_XML_PATH, ScopeInterface::SCOPE_STORE) ?? '';
    }

    /**
     * @return int
     */
    public function getMainAttributeIgnore(): int
    {
        return (int)$this->scopeConfig->getValue(self::MAIN_ATTRIBUTE_IGNORE_XML_PATH, ScopeInterface::SCOPE_STORE) ?? 0;
    }

    /**
     * @return string
     */
    public function getSecondaryAttribute(): string
    {
        return $this->scopeConfig->getValue(self::SECONDARY_ATTRIBUTE_XML_PATH, ScopeInterface::SCOPE_STORE) ?? "";
    }

    /**
     * @return string
     */
    public function getTertiaryAttribute(): string
    {
        return $this->scopeConfig->getValue(self::TERTIARY_ATTRIBUTE_XML_PATH, ScopeInterface::SCOPE_STORE) ?? "";
    }
    /**
     * @return float
     */
    public function getTertiatyAttributeBoost(): float
    {
        return (float)$this->scopeConfig->getValue(self::TERTIARY_ATTRIBUTE_BOOST_XML_PATH, ScopeInterface::SCOPE_STORE) ?? 1.0;
    }
    /**
     * @return string
     */
    public function getTertiaryAttributeClause(): string
    {
        return $this->scopeConfig->getValue(self::TERTIARY_ATTRIBUTE_CLAUSE_XML_PATH, ScopeInterface::SCOPE_STORE) ?? '';
    }

    /**
     * @return int
     */
    public function getSecondaryAttributeWidth(): int
    {
        return (int)$this->scopeConfig->getValue(self::SECONDARY_ATTRIBUTE_WIDTH_XML_PATH, ScopeInterface::SCOPE_STORE) ?? 1;
    }

    /**
     * @return int
     */
    public function getListSize(): int
    {
        return (int)$this->scopeConfig->getValue(self::LIST_SIZE_XML_PATH, ScopeInterface::SCOPE_STORE);
    }

    public function getUseParentCategoryThreshold(): int{
        return (int)$this->scopeConfig->getValue(self::USE_PARENT_CATEGORY_THRESHOLD_XML_PATH, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return null|string
     */
    public function getButtonFontSize(): ?string
    {
        return $this->scopeConfig->getValue(self::BUTTON_FONT_SIZE, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return null|string
     */
    public function getButtonFontWeight(): ?string
    {
        return $this->scopeConfig->getValue(self::BUTTON_FONT_WEIGHT, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return null|string
     */
    public function getButtonText(): ?string
    {
        return $this->scopeConfig->getValue(self::BUTTON_TEXT, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Find the deepest category
     * @param Product $product
     * @return Category|null
     */
    public function getDeepestCategory(Product $product)
    {
        $depth = 0;
        $deepestCategory = null;

        /** @var Category $category */
        foreach ($product->getCategoryCollection() as $category) {
            if (($levels = substr_count($category->getPath(), "/")) > $depth) {
                $depth = $levels;
                $deepestCategory = $category;
            }
        }

        return $deepestCategory;
    }

    public function getSimilarToUrl($product, $useParentCategory = false, $searchPageTrigger = false, $filters = []): false|string
    {
        $queryParams = [];
        if (isset($filters['filters']) && count($filters['filters'])) {
            foreach ($filters['filters'] as $key => $value) {
                $queryParams[] = $key . "=" . $value;
            }
        }
        if (isset($filters['sort']) && count($filters['sort'])) {
            foreach ($filters['sort'] as $key => $value) {
                $queryParams[] = "sort_".$key . "=" . $value;
            }
        }

        $queryString = implode('&', $queryParams);
        if ($searchPageTrigger) {
            return $this->urlBuilder->getUrl("catalogsearch/result/?similar={$product->getId()}&q=".$product->getName()."&".$queryString);
        }

        $category = $this->getDeepestCategory($product);
        if($useParentCategory){
            $category = $category->getParentCategory();
        }

        if (null != $category){
            if ($queryString != "") {
                return $this->urlBuilder->getUrl($category->getUrl()."?similar={$product->getId()}&".$queryString);
            } else {
                return $this->urlBuilder->getUrl($category->getUrl(). '?similarTo='.$product->getSku());
            }
        } else{
            return false;
        }
    }

    public function getAttributeUsedForSortByArray(): ?array
    {
        $_options = explode(',',$this->scopeConfig->getValue(self::SORTBY_ATTRIBUTES_XML_PATH, ScopeInterface::SCOPE_STORE) ?? []);
        $options = ['random' => ['label' => __('Position'), 'value' => 'random']];

        foreach ($this->catalogConfig->getAttributesUsedForSortBy() as $_key => $attribute) {
            /* @var $attribute AbstractAttribute */

            if (in_array($attribute->getAttributeCode(), $_options)) {
                $options[$attribute->getAttributeCode()] = $attribute->getStoreLabel();
            }
        }

        return $options;
    }

    protected function getMainAttributeData(){
        if (null === $this->mainAttribute){
            $this->mainAttribute = $this->attributeRepository->get('catalog_product', $this->getMainAttribute());
        }
        return $this->mainAttribute;
    }
    protected function getSecondaryAttributeData(){
        if (null === $this->secondaryAttribute){
            $this->secondaryAttribute = $this->attributeRepository->get('catalog_product', $this->getSecondaryAttribute());
        }
        return $this->secondaryAttribute;
    }
    protected function getTertiaryAttributeData(){
        if (null === $this->secondaryAttribute){
            $this->tertiaryAttribute = $this->attributeRepository->get('catalog_product', $this->getTertiaryAttribute());
        }
        return $this->tertiaryAttribute;
    }

    public function getRequest(){
        return $this->_request;
    }

    public function getSimilarTo(){
        return $this->getRequest()->getParam('similarTo');
    }

    public function getSimilar()
    {
        return $this->getRequest()->getParam('similar');
    }

    public function isSimilarExists()
    {
        if ($this->getSimilar()) {
            return true;
        }
        return false;
    }

    public function getColorSort()
    {
        return $this->getRequest()->getParam('sort_color');
    }
    public function isColorSortExists()
    {
        if ($this->getColorSort()) {
            return true;
        }
        return false;
    }

    public function getSimilarId(): int
    {
        return (int) $this->getRequest()->getParam("similar");
    }


    public function isSimilarTo(){
        if($similarTo = $this->getSimilarTo()){
            return true;
        }
        return false;
    }

    public function onProductPage(): bool{
        return ('catalog_product_view' === $this->getRequest()->getFullActionName());
    }

    public function onProductifyAjaxPage(): bool{
        return ('productify_similarto_ajax' === $this->getRequest()->getFullActionName());
    }

    public function getSimilarToProduct(){
        if (null === $this->product){
            if ($similarTo = $this->getSimilarTo()){
                $this->product = $this->productRepository->get($similarTo);
            } else if ($this->onProductPage()){
                $this->product = $this->coreRegistry->registry('product');
            }
        }
        return $this->product;
    }

    public function getSimilarProduct()
    {
        if ($similar = $this->getSimilar()) {
            $this->product = $this->productRepository->getById($similar);
        } else if ($this->onProductPage()){
            $this->product = $this->coreRegistry->registry('product');
        }
        return $this->product;
    }

    public function getMainAttributeId(){
        try {
            $mainAttribute = $this->getMainAttributeData();
        } catch (NoSuchEntityException $e) {
            return false;
        } catch (Exception $e) {
            return false;
        }
        return $mainAttribute->getAttributeId();
    }

    public function getSecondaryAttributeId(){
        try {
            $secondaryAttribute = $this->getSecondaryAttributeData();
        } catch (NoSuchEntityException $e) {
            return false;
        } catch (Exception $e) {
            return false;
        }
        return $secondaryAttribute->getAttributeId();
    }

    public function getTertiaryAttributeId(){
        try {
            $attribute = $this->getTertiaryAttributeData();
        } catch (NoSuchEntityException $e) {
            return false;
        } catch (Exception $e) {
            return false;
        }
        return $attribute->getAttributeId();
    }
}
