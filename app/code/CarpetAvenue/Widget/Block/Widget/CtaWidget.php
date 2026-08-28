<?php
declare(strict_types=1);

namespace CarpetAvenue\Widget\Block\Widget;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\View\Element\Template;
use Magento\Widget\Block\BlockInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Block\Product\AbstractProduct;

class CtaWidget extends Template implements BlockInterface
{
    protected $_template = "CarpetAvenue_Widget::widget/cta_banner.phtml";

    public function __construct(
        Template\Context $context,
        protected ProductRepositoryInterface $productRepository,
        protected  AbstractProduct $abstractProduct,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getProductBySku($sku): ?ProductInterface
    {
        $_product = null;
        try {
            $_product = $this->productRepository->get($sku);
        } catch (NoSuchEntityException $e) {
            $this->_logger->critical($e->getMessage());
        }
        return $_product;
    }

    public function getProductPrice($product): string
    {
        $_priceHtml = "";
        try {
            $_priceHtml = $this->abstractProduct->getProductPrice($product);
        } catch (NoSuchEntityException $e) {
            $this->_logger->critical($e->getMessage());
        }
        return $_priceHtml;
    }

    public function getDotX() { return $this->getData('dot_x'); }
    public function getDotY() { return $this->getData('dot_y'); }
    public function getProductSku() { return $this->getData('product_sku'); }
    public function getTitle() { return $this->getData('title'); }
    public function getDescription() { return $this->getData('description'); }
    public function getButtonText() { return $this->getData('button_text'); }
    public function getButtonLink() { return $this->getData('button_link'); }
    public function getBgColor() { return $this->getData('bgcolor'); }
    public function getTextColor() { return $this->getData('textcolor'); }
    public function getFullwidth() { return $this->getData('is_fullwidth'); }
    public function getAlignContent() { return $this->getData('align_content'); }
    public function getImageLayout() { return $this->getData('image_layout'); }
    public function getImageLayoutMobile() { return $this->getData('image_layout_mobile'); }
    public function getMargin() { return $this->getData('margin'); }
    public function getMarginMobile() { return $this->getData('margin_mobile'); }
    public function getPadding() { return $this->getData('padding'); }
    public function getPaddingMobile() { return $this->getData('padding_mobile'); }
    public function getImage() { return $this->getData('banner_image'); }
    public function getButtonColor() { return $this->getData('buttoncolor'); }
    public function getDescColor() { return $this->getData('textcolor_desc'); }
    public function getButtonTextColor() { return $this->getData('textcolor_button'); }
    public function getHeadlineSize() { return $this->getData('headline_size'); }
    public function getDescSize() { return $this->getData('description_size'); }
    public function getButtonSize() { return $this->getData('button_size'); }
}
