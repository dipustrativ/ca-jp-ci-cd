<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Marketing
 * @package   CarpetAvenue_Trackify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Trackify\Block;

use Magento\Framework\App\Request\Http;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use CarpetAvenue\Trackify\Helper\TagManagerHelper;

class TagManager extends Template
{
    public function __construct(
        Context $context,
        protected TagManagerHelper $tagManagerHelper,
        protected Http $request,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }
    
    /**
     * @return string the tag manager container (javascript)
     */
    public function getContainer(): string
    {
        return $this->tagManagerHelper->getContainer();
    }
    
    /**
     * @return string the tag manager container (no script)
     */
    public function getNoScriptContainer(): string
    {
        return $this->tagManagerHelper->getNoScriptContainer();
    }
    
    /**
     * Render GA tracking scripts
     *
     * @return string
     */
    protected function _toHtml(): string
    {
        if (!$this->tagManagerHelper->isGoogleTagManagerEnabled()) {
            return '';
        }

        return parent::_toHtml();
    }

    public function getPageType(): string
    {
        $fullActionName = $this->request->getFullActionName();
        $pageTypeArray = array(
            "cms_index_index"              => 'home',
            "catalog_category_view"        => 'category',
            "catalogsearch_result_index"   => 'searchresult',
            "catalog_product_view"         => 'product',
            "checkout_cart_index"          => 'cart',
            "onestepcheckout_index_index"  => 'initcheckout'
        );
        if (array_key_exists($fullActionName, $pageTypeArray)) {
            return $pageTypeArray[$fullActionName];
        } else {
            return "other";
        }
    }
}
