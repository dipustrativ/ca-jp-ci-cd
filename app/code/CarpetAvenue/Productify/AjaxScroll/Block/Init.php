<?php
/**
 * Landofcoder
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Landofcoder.com license that is
 * available through the world-wide-web at this URL:
 * http://landofcoder.com/license
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category   Landofcoder
 * @package    Lof_AjaxScroll
 *
 * @copyright  Copyright (c) 2016 Landofcoder (http://www.landofcoder.com/)
 * @license    http://www.landofcoder.com/LICENSE-1.0.html
 */

namespace CarpetAvenue\Productify\AjaxScroll\Block;

class Init extends \Magento\Framework\View\Element\Template
{

    protected $coreRegistry = null;
    /**
     * @var \CarpetAvenue\Productify\AjaxScroll\Helper\Data
     */
    public $helperData;
    protected $storeManager;

    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \CarpetAvenue\Productify\AjaxScroll\Helper\Data                      $helperData
     * @param \Magento\Framework\Registry                      $registry
     * @param array                                            $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \CarpetAvenue\Productify\AjaxScroll\Helper\Data $helperData,
        \Magento\Framework\Registry $registry,
        array $data = []
        ) {
        parent::__construct($context, $data);
        $this->helperData    = $helperData;
        $this->coreRegistry = $registry;
        $this->storeManager = $context->getStoreManager();
    }

    public function getProductListMode()
    {
        if ($currentMode = $this->getRequest()->getParam('product_list_mode')) {
            switch($currentMode){
                case 'grid':
                $productListMode = 'grid';
                break;
                case 'list':
                $productListMode = 'list';
                break;
                default:
                $productListMode = 'grid';
            }
        }
        else {
            $defaultMode = $this->helperData->getConfig('catalog/frontend/list_mode');
            switch($defaultMode){
                case 'grid-list':
                $productListMode = 'grid';
                break;
                case 'list-grid':
                $productListMode = 'list';
                break;
                case 'list':
                $productListMode = 'list';
                break;
                case 'grid':
                $productListMode = 'grid';
                break;
                default:
                $productListMode = 'grid';
            }
        }

        return $productListMode;
    }

    public function isEnable() {
        $fullAction       = $this->getRequest()->getFullActionName();
        // Every category page gets it — the old per-category whitelist silently
        // excluded any category created after it was last edited.
        if ($fullAction == 'catalog_category_view' && $this->coreRegistry->registry('current_category')) {
            return (bool) $this->helperData->getConfig('productify/ajaxscroll/instances/enabled_grid');
        }
        $enabled_search   = $this->helperData->getConfig('productify/ajaxscroll/instances/enabled_search');
        $enabled_advanced = $this->helperData->getConfig('productify/ajaxscroll/instances/enabled_advanced');
        if (($enabled_search && $fullAction == 'catalogsearch_result_index') || ($enabled_advanced && $fullAction == 'catalogsearch_advanced_result')) {
            return true;
        }

        $enabled_similar = $this->helperData->getConfig("productify/ajaxscroll/instances/enabled_similar");
        if($enabled_similar && $fullAction == 'similar_products_view') {
            return true;
        }
        return false;
    }

    /**
     * @return bool|false
     */
    public function getLoaderImage()
    {

        $url = $this->helperData->getConfig('productify/ajaxscroll/design/loading_image');
        if(!empty($url)) {
            $url = strpos($url, 'http') === 0 ? $url : $this->getViewFileUrl($url);
        }
        return empty($url) ? false : $url;
    }
    // public function getViewFileUrl($url) {
    //     return $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA . $url);
    // }
}
