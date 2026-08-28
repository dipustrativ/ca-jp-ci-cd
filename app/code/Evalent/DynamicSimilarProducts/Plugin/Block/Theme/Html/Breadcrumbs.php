<?php

namespace Evalent\DynamicSimilarProducts\Plugin\Block\Theme\Html;

class Breadcrumbs extends \Magento\Theme\Block\Html\Breadcrumbs
{
    protected \Evalent\DynamicSimilarProducts\Helper\Data $dataHelper;
    protected \Magento\Framework\Registry $registry;
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Evalent\DynamicSimilarProducts\Helper\Data $dataHelper,
        \Magento\Framework\Registry $registry,
                                                         $data = []
    ){
        parent::__construct($context, $data);
        $this->dataHelper = $dataHelper;
        $this->registry = $registry;
    }
    /**
     * @return array
     */
    public function getCrumbs()
    {
        return $this->_crumbs;
    }

    /**
     * @param $index
     * @param array $data
     * @return $this
     */
    public function setCrumb($index, array $data)
    {
        if (isset($this->_crumbs[$index])) {
            $this->_crumbs[$index] = $data;
        }
        return $this;
    }

    protected function _toHtml(){
        if (($this->dataHelper->isSimilarTo())){
            $product = $this->dataHelper->getSimilarToProduct();
            $oldCrumbs = $this->getCrumbs();
            foreach ($this->_crumbs as $key => $crumb) {
                if (!isset($crumb['link']) || strlen($crumb['link']) === 0) {
                    //$crumb['link'] = $product->getProductUrl();
                    $category = $this->registry->registry('current_category');
                    $crumb['link'] = $category->getUrl($category);
                    $this->setCrumb($key, $crumb);
                    break;
                }
            }
            $this->addCrumb('similar',
                [
                    'label' => __("Similar Products to %1", $product->getSku()),
                    'title' => __("Similar Products to %1", $product->getSku()),
                    'link' => $product->getProductUrl()
                ]
            );
            $html = parent::_toHtml();
            $this->_crumbs = $oldCrumbs;
            return $html;
        }

        if ($this->dataHelper->isSimilarExists()) {
            $product = $this->dataHelper->getSimilarProduct();
            foreach ($this->_crumbs as $key => $crumb) {
                if (!isset($crumb['link']) || strlen($crumb['link']) === 0) {
                    $category = $this->registry->registry('current_category');
                    if($category) {
                        $crumb['link'] = $category->getUrl($category);
                        $this->setCrumb($key, $crumb);
                        break;
                    }
                }
            }
            $this->addCrumb('similar',
                [
                    'label' => __("Similar Products to %1", $product->getSku()),
                    'title' => __("Similar Products to %1", $product->getSku()),
                    'link' => $this->getUrl()
                ]
            );

            return parent::_toHtml();
        }
        return parent::_toHtml();
    }
}
