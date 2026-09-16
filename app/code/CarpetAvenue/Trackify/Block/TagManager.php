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

use CarpetAvenue\Trackify\Helper\Data;
use CarpetAvenue\Trackify\Helper\TagManagerHelper;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\View\Page\Config as PageConfig;

class TagManager extends Template
{
    /**
     * Full action name => the `pageType` value GTM sees.
     *
     * Vocabulary matches the container that runs on production, so the same triggers
     * work after cutover.
     */
    private const PAGE_TYPES = [
        'cms_index_index'             => 'home',
        'catalog_category_view'       => 'category',
        'catalogsearch_result_index'  => 'searchresult',
        'catalog_product_view'        => 'product',
        'checkout_cart_index'         => 'cart',
        'onestepcheckout_index_index' => 'initcheckout',
        'checkout_onepage_success'    => 'success',
    ];

    public function __construct(
        Context $context,
        protected TagManagerHelper $tagManagerHelper,
        protected Data $trackifyData,
        protected Http $request,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Configured GTM container ID, empty when unset or malformed.
     *
     * @return string
     */
    public function getContainerId(): string
    {
        return $this->tagManagerHelper->getContainerId();
    }

    /**
     * Page-level dataLayer variables, pushed before the container loads.
     *
     * @return array<string, string>
     */
    public function getPageVars(): array
    {
        return [
            'pageType'     => $this->getPageType(),
            'currentStore' => $this->getStoreCode(),
            'pageLanguage' => $this->getPageLanguage(),
            'currency'     => $this->trackifyData->getCurrentCurrencyCode(),
        ];
    }

    /**
     * @return string
     */
    public function getPageType(): string
    {
        return self::PAGE_TYPES[$this->request->getFullActionName()] ?? 'other';
    }

    /**
     * The language code this page actually renders in its <html lang> attribute.
     *
     * Read straight off PageConfig rather than re-derived from the locale, so a GTM
     * variable keyed on this can never disagree with the markup. PageConfig sets it in
     * its own constructor, so it is populated well before this block renders.
     *
     * Exists so container-side tags that must vary per market (the CookieScript consent
     * widget, for one) can switch on a dataLayer variable instead of on Page Hostname,
     * which differs across dev, staging and production for the same storefront.
     *
     * @return string
     */
    public function getPageLanguage(): string
    {
        return (string) $this->pageConfig->getElementAttribute(
            PageConfig::ELEMENT_TYPE_HTML,
            PageConfig::HTML_ATTRIBUTE_LANG
        );
    }

    /**
     * @return string
     */
    public function getStoreCode(): string
    {
        try {
            return (string) $this->_storeManager->getStore()->getCode();
        } catch (NoSuchEntityException) {
            return '';
        }
    }

    /**
     * Render nothing at all unless GTM is switched on and has a usable container ID.
     *
     * @return string
     */
    protected function _toHtml(): string
    {
        if (!$this->tagManagerHelper->isGoogleTagManagerEnabled() || $this->getContainerId() === '') {
            return '';
        }

        return parent::_toHtml();
    }
}
