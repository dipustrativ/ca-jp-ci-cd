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

namespace CarpetAvenue\Productify\Block\Adminhtml\System\Config\RewriteURL;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class GenerateMissingURLRewrite extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $html = '<button type="button" id="generate-missing-url-btn" class="action-default scalable" onclick="generateMissingUrls()">
                    <span>Generate Missing URLs</span>
                 </button>';

        $html .= '<div id="generate-missing-url-loading" style="display: none; margin-top: 15px;vertical-align: middle;flex-direction: row;flex-wrap: nowrap;justify-content: flex-start;align-items: center;">
                    <img style="margin-right: 10px; max-width: 30px;" src="' . $this->getViewFileUrl('images/loader-1.gif') . '" alt="Loading..." />
                    Generating missing URLs...
                  </div>';

        $html .= '<div id="generate-missing-url-result" style="margin-top: 15px; min-width: 650px"></div>';

        $generateUrl = $this->getUrl('productify/rewriteurl/generatemissing');

        $html .= '<script type="text/javascript">
            function generateMissingUrls() {
                const resultDiv = document.getElementById("generate-missing-url-result");
                const loadingDiv = document.getElementById("generate-missing-url-loading");

                var formData = new FormData();
                formData.append("form_key", FORM_KEY);

                loadingDiv.style.display = "flex";
                resultDiv.innerHTML = "";

                var xhr = new XMLHttpRequest();
                xhr.open("POST", "' . $generateUrl . '", true);

                xhr.onload = function() {
                    loadingDiv.style.display = "none";

                    if (xhr.status === 200) {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            let resultHtml = "<div style=\"color: green; font-size: 10px; padding: 10px; background: #f0f8f0; border: 1px solid #d4edda; overflow: scroll;max-height: 1000px; \">";
                            resultHtml += response.message;
                            resultDiv.innerHTML = resultHtml;
                        } else {
                            resultDiv.innerHTML = "<div style=\"color: red; font-size: 10px; padding: 10px; background: #f8f0f0; border: 1px solid #f5c6cb; overflow: scroll;max-height: 1000px;\">" + response.message + "</div>";
                        }
                    } else {
                        resultDiv.innerHTML = "<div style=\"color: red;\">An error occurred while generating URLs.</div>";
                    }
                };

                xhr.onerror = function() {
                    loadingDiv.style.display = "none";
                    resultDiv.innerHTML = "<div style=\"color: red;\">Network error occurred.</div>";
                };

                xhr.send(formData);
            }
        </script>';

        return $html;
    }
}