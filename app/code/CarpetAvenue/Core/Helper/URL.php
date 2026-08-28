<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Core
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Core\Helper;

use Exception;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Magento All in All URL Helper
 *
 * Provides All Necessary URL in Single Place
 *
 */
class URL extends AbstractHelper
{
    /**
     *
     * @param Context $context
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Context $context,
        protected StoreManagerInterface $storeManager,
    ) {
        parent::__construct($context);
    }

    /**
     * Get Store Base URL
     *
     * This will Back the store's web secure URL (Storefront Base URL in Short)
     *
     * @return string|null
     */
    public function getBaseUrl(): ?string
    {
        try {
            return $this->storeManager
                ->getStore()
                ->getBaseUrl(UrlInterface::URL_TYPE_WEB, true);
        } catch (Exception) {
            return "";
        }
    }

    /**
     * Get Store Media URL
     *
     * This will Back the store's web secure URL (Storefront Base URL in Short)
     *
     * */
    public function getMediaUrl(): ?string
    {
        try {
            return $this->storeManager
                ->getStore()
                ->getBaseUrl(UrlInterface::URL_TYPE_MEDIA, true);
        } catch (Exception) {
            return "";
        }
    }

    /**
     * Get Store Media URL
     *
     * This will Back the store's web secure URL (Storefront Base URL in Short)
     *
     * */
    public function getStaticUrl(): ?string
    {
        try {
            return $this->storeManager
                ->getStore()
                ->getBaseUrl(UrlInterface::URL_TYPE_STATIC, true);
        } catch (Exception) {
            return "";
        }
    }

    /**
     * Get Any Secure Storefront URL by Route
     *
     * @param string $route Route String Exclude Base URL
     * @param array $params URL Parameter key value array
     * @return string Full Url String
     * */
    public function getUrl(string $route, array $params = []): string
    {
        $params['_secure'] = true;
        return parent::_getUrl($route, $params);
    }
}