<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;

class Config extends AbstractHelper
{
    const XML_PATH_AUTO_SYNC_ENABLED = 'productify/cache/auto_sync_enabled';
    const XML_PATH_FULL_REINDEX_ENABLED = 'productify/general/enabled_full_reindex';
    const XML_PATH_URL_REWRITE_UPDATE_PRODUCT_ENABLED = 'productify/url_rewrite/update_url/enabled';
    const XML_PATH_URL_REWRITE_TEMPLATE = 'catalog/seo/product_url_generation/template';

    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    public function isAutoSyncEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_AUTO_SYNC_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function isFullReindexEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_FULL_REINDEX_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function isCronJobEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            'productify/cronjob/enabled',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isUpdateProductUrlRewriteEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_URL_REWRITE_UPDATE_PRODUCT_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getUrlRewriteTemplate($storeId = null): string
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_URL_REWRITE_TEMPLATE,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getProductDetailsCustomAddToCart($storeId = null): array
    {
        $dataArray = array(
            'enabled' => false,
            'background_color' => '',
            'color' => '',
            'category_ids' => []
        );

        $isEnabled = $this->scopeConfig->isSetFlag(
            'productify/product_details/custom_add_to_cart/enabled',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (!$isEnabled) {
            return $dataArray;
        }

        $dataArray['enabled'] = true;

        $dataArray['background_color'] = $this->scopeConfig->getValue(
            'productify/product_details/custom_add_to_cart/button_background_color',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?? '';

        $dataArray['color'] = $this->scopeConfig->getValue(
            'productify/product_details/custom_add_to_cart/button_color',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?? '';

        $categoryIds = $this->scopeConfig->getValue(
            'productify/product_details/custom_add_to_cart/category_choose',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?? '';

        // Parse category_ids from comma-separated string to array
        if (!empty($categoryIds)) {
            $dataArray['category_ids'] = array_map('trim', explode(',', $categoryIds));
        }

        return $dataArray;
    }
}