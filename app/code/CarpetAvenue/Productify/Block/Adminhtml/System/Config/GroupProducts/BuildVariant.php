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

namespace CarpetAvenue\Productify\Block\Adminhtml\System\Config\GroupProducts;

use CarpetAvenue\Productify\Helper\Config;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class BuildVariant extends Field
{
    protected Config $configHelper;

    public function __construct(
        Context $context,
        Config $configHelper,
        array $data = []
    ) {
        $this->configHelper = $configHelper;
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $isAutoSync = $this->configHelper->isAutoSyncEnabled();
        $statusClass = $isAutoSync ? 'grid-severity-notice notice-badge' : 'grid-severity-minor notice-badge';
        $statusText = $isAutoSync ? 'Enabled' : 'Disabled';

        return sprintf('
        <style>
            .build-variant-container { margin-bottom: 15px; }
            .status-badge { padding: 3px 8px; border-radius: 3px; }
            .build-loading { display: none; margin-top: 15px; align-items: center; }
            .build-loading img { margin-right: 10px; }
            .build-result { margin-top: 15px; }
            .result-success { color: green; font-size: 12px; padding: 10px; background: #f0f8f0; border: 1px solid #d4edda; }
            .result-error { color: red; font-size: 12px; padding: 10px; background: #f8f0f0; border: 1px solid #f5c6cb; }
            .notice-badge {margin-top: 5px; max-width: 160px; padding: 5px 0 !important;}
        </style>

        <div class="build-variant-container">
            <strong>Auto Sync Status: </strong>
            <span class="%s status-badge">%s</span>
        </div>

        <button type="button" id="build-variant-btn" class="action-default scalable" onclick="buildVariantCache()">
            <span>Build Variant Cache</span>
        </button>

        <div id="build-loading" class="build-loading">
            <img src="%s" alt="Loading..." />
            Building variant cache...
        </div>

        <div id="build-result" class="build-result"></div>

        <script>
            const buildUrl = "%s";

            function buildVariantCache() {
                const btn = document.getElementById("build-variant-btn");
                const result = document.getElementById("build-result");
                const loading = document.getElementById("build-loading");

                btn.disabled = true;
                loading.style.display = "flex";
                result.innerHTML = "";

                fetch(buildUrl, {
                    method: "POST",
                    body: new FormData().append("form_key", FORM_KEY) || new URLSearchParams({form_key: FORM_KEY})
                })
                .then(res => res.json())
                .then(data => {
                    showResult(data.success ? "result-success" : "result-error", data.message);
                })
                .catch(() => showResult("result-error", "Network error occurred."))
                .finally(() => {
                    loading.style.display = "none";
                    btn.disabled = false;
                });
            }

            function showResult(className, message) {
                document.getElementById("build-result").innerHTML = `<div class="${className}">${message}</div>`;
            }
        </script>',
            $statusClass,
            $statusText,
            $this->getViewFileUrl('images/loader-1.gif'),
            $this->getUrl('productify/groupproducts/buildvarient')
        );
    }
}