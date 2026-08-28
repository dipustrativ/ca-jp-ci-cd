<?php

declare(strict_types=1);

namespace Carpient\Theme\ViewModel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Exposes the "cart as slide-out drawer" storefront setting to templates.
 */
class CartConfig implements ArgumentInterface
{
    private const XML_PATH_ENABLE_DRAWER = 'carpient/cart/enable_drawer';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether the minicart should open as a slide-out drawer.
     */
    public function isDrawerEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLE_DRAWER,
            ScopeInterface::SCOPE_STORE
        );
    }
}
