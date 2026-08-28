<?php

declare(strict_types=1);

namespace Evalent\DynamicSimilarProducts\Block\Similar\Products;

use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

class View extends \Magento\Catalog\Block\Category\View
{
    protected $_product;
    /**
     * @return View
     * @throws LocalizedException
     */
    protected function _prepareLayout()
    {
        parent::_prepareLayout();
        $title = $this->_getTitle();
        $this->pageConfig->getTitle()->set($title);
        $this->pageConfig->setDescription($title);
        $this->_updateBreadcrumbs();

        $pageMainTitle = $this->getLayout()->getBlock('page.main.title');
        if ($pageMainTitle) {
            $pageMainTitle->setPageTitle($title);
        }

        return $this;
    }
    /**
     * @return string
     */
    public function getProductListHtml()
    {
        return $this->getChildHtml('product_list');
    }
    /**
     * Return identifiers for produced content
     *
     * @return array
     */
    public function getIdentities()
    {
        return $this->_getProduct()->getIdentities();
    }

    /**
     * @return Phrase
     */
    private function _getTitle()
    {
        $product = $this->_getProduct();
        if (isset($product)) {
            return __("Similar Products to %1", $product->getSku());
        }
        return __("Similar Products");
    }

    /**
     * @return Product|mixed|null
     */
    protected function _getProduct()
    {
        if (!isset($this->_product)) {
            $this->_product = $this->_coreRegistry->registry('product');
        }

        return $this->_product;
    }

    private function _updateBreadcrumbs()
    {
        /** @var Product $product */
        $product = $this->_getProduct();
        if (!isset($product)) {
            return;
        }
        /** @var \Evalent\DynamicSimilarProducts\Plugin\Block\Theme\Html\Breadcrumbs $breadcrumbsBlock */
        if ($breadcrumbsBlock = $this->getLayout()->getBlock('breadcrumbs')) {
            $crumbs = $breadcrumbsBlock->getCrumbs();
            foreach ($crumbs as $key => $crumb) {
                if (!isset($crumb['link']) || strlen($crumb['link']) === 0) {
                    //$crumb['link'] = $product->getProductUrl();
                    $crumb['link'] = $this->getUrl();
                    $breadcrumbsBlock->setCrumb($key, $crumb);
                    break;
                }
            }
            $title = $this->_getTitle();
            $breadcrumbsBlock->addCrumb(
                'similar',
                [
                    'label' => $title,
                    'title' => $title,
                    'link' => $product->getProductUrl()
                ]
            );
        }
    }
}
