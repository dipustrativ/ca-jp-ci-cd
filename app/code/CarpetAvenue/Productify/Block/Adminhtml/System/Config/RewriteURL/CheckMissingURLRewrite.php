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

class CheckMissingURLRewrite extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $html = '<button type="button" id="check-missing-url-btn" class="action-default scalable" onclick="checkMissingUrls()">
                    <span>Check Missing URLs</span>
                 </button>';

        $html .= '<div id="check-missing-url-loading" style="display: none; margin-top: 15px;vertical-align: middle;flex-direction: row;flex-wrap: nowrap;justify-content: flex-start;align-items: center;">
                    <img style="margin-right: 10px; max-width: 30px;" src="' . $this->getViewFileUrl('images/loader-1.gif') . '" alt="Loading..." />
                    Checking for missing URLs...
                  </div>';

        $html .= '<div id="check-missing-url-result" style="margin-top: 15px; min-width: 650px"></div>';

        $checkUrl = $this->getUrl('productify/rewriteurl/checkmissing');

        $html .= '<script type="text/javascript">
            function checkMissingUrls() {
                const resultDiv = document.getElementById("check-missing-url-result");
                const loadingDiv = document.getElementById("check-missing-url-loading");

                var formData = new FormData();
                formData.append("form_key", FORM_KEY);

                loadingDiv.style.display = "flex";
                resultDiv.innerHTML = "";

                var xhr = new XMLHttpRequest();
                xhr.open("POST", "' . $checkUrl . '", true);

                xhr.onload = function() {
                    loadingDiv.style.display = "none";

                    if (xhr.status === 200) {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            let resultHtml = "<div style=\"color: green; font-size: 10px; overflow-x: scroll; padding: 10px; background: #f0f8f0; border: 1px solid #d4edda;\">";
                            resultHtml += "<strong>" + response.message + "</strong>";
                            
                            if (response.missing_urls && response.missing_urls.length > 0) {
                                resultHtml += "<br><br><strong>Missing URLs found (" + response.missing_urls.length + "):</strong><br>";
                                resultHtml += "<br># | Product ID | SKU | Actual Path | Current Path<br>";
                                resultHtml += "<ul style=\"margin: 5px 0;padding-left: 20px;overflow: scroll;max-height: 1000px;\">";
                                response.missing_urls.forEach(function(url) {
                                    resultHtml += "<li>" + url + "</li>";
                                });
                                resultHtml += "</ul>";
                                
                                // Display unique SKUs
                                if (response.unique_skus && response.unique_skus.length > 0) {
                                    resultHtml += "<br><strong>Unique SKUs (" + response.unique_sku_count + "):</strong><br>";
                                    resultHtml += "<div style=\"background: #f9f9f9; padding: 8px; border-left: 3px solid #007cba;\">";
                                    resultHtml += response.unique_skus.join(", ");
                                    resultHtml += "</div>";
                                }
                            }
                            resultHtml += "</div>";
                            resultDiv.innerHTML = resultHtml;
                        } else {
                            resultDiv.innerHTML = "<div style=\"color: red; font-size: 12px; padding: 10px; background: #f8f0f0; border: 1px solid #f5c6cb;\">" + response.message + "</div>";
                        }
                    } else {
                        resultDiv.innerHTML = "<div style=\"color: red;\">An error occurred while checking URLs.</div>";
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