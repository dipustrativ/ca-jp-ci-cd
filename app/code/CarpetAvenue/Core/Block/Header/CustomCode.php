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

namespace CarpetAvenue\Core\Block\Header;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class CustomCode extends Template
{
    /**
     * @var ScopeConfigInterface
     */
    protected ScopeConfigInterface $scopeConfig;

    /**
     * @param Context $context
     * @param array $data
     */
    public function __construct(
        Context $context,
        array $data = []
    ) {
        $this->scopeConfig = $context->getScopeConfig();
        parent::__construct($context, $data);
    }

    /**
     * Get config value
     *
     * @param string $path
     * @return string
     */
    protected function getConfigValue(string $path): string
    {
        $value = $this->scopeConfig->getValue(
            $path,
            ScopeInterface::SCOPE_STORE
        );

        return $value ? trim((string)$value) : '';
    }

    /**
     * Get global CSS
     *
     * @return string
     */
    public function getGlobalCss(): string
    {
        return $this->getConfigValue('carpetavenue_custom_code/head_includes/global_css');
    }

    /**
     * Get global JavaScript
     *
     * @return string
     */
    public function getGlobalJs(): string
    {
        return $this->getConfigValue('carpetavenue_custom_code/head_includes/global_js');
    }

    /**
     * Get store view CSS
     *
     * @return string
     */
    public function getStoreViewCss(): string
    {
        return $this->getConfigValue('carpetavenue_custom_code/head_includes/store_view_css');
    }

    /**
     * Get store view JavaScript
     *
     * @return string
     */
    public function getStoreViewJs(): string
    {
        return $this->getConfigValue('carpetavenue_custom_code/head_includes/store_view_js');
    }

    /**
     * Wrap CSS code with style tags
     *
     * @param string $css
     * @return string
     */
    protected function wrapCss(string $css): string
    {
        if (empty($css)) {
            return '';
        }

        return sprintf("<style>\n%s\n</style>\n", $css);
    }

    /**
     * Wrap JavaScript code with script tags
     *
     * @param string $js
     * @return string
     */
    protected function wrapJs(string $js): string
    {
        if (empty($js)) {
            return '';
        }

        return sprintf("<script type=\"text/javascript\">\n%s\n</script>\n", $js);
    }

    /**
     * Get combined custom code
     *
     * @return string
     */
    public function getCustomCode(): string
    {
        $output = '';

        // Add global CSS
        $globalCss = $this->getGlobalCss();
        if (!empty($globalCss)) {
            $output .= $this->wrapCss($globalCss);
        }

        // Add global JavaScript
        $globalJs = $this->getGlobalJs();
        if (!empty($globalJs)) {
            $output .= $this->wrapJs($globalJs);
        }

        // Add store view specific CSS
        $storeViewCss = $this->getStoreViewCss();
        if (!empty($storeViewCss)) {
            $output .= $this->wrapCss($storeViewCss);
        }

        // Add store view specific JavaScript
        $storeViewJs = $this->getStoreViewJs();
        if (!empty($storeViewJs)) {
            $output .= $this->wrapJs($storeViewJs);
        }

        return $output;
    }
}
