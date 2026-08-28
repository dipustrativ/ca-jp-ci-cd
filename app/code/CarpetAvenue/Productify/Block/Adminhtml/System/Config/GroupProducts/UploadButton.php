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

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class UploadButton extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $html = '<button type="button" id="upload-csv-btn" class="action-default scalable" onclick="uploadCsv()">
                    <span>Upload / Update</span>
                 </button>';
        $html .= '<div id="upload-loading" style="display: none; margin-top: 15px;vertical-align: middle;flex-direction: row;flex-wrap: nowrap;justify-content: flex-start;align-items: center;">
                    <img style="margin-right: 10px" src="' . $this->getViewFileUrl('images/loader-1.gif') . '" alt="Loading..." />
                    Processing...
                  </div>';

        $uploadUrl = $this->getUrl('productify/groupproducts/upload');

        $html .= '<script type="text/javascript">
            function uploadCsv() {
                const fileInput = document.getElementById("productify_group_products_csv_upload");
                
                const overwriteDesignId = document.getElementById("productify_group_products_overwrite_with_csv_design_id").value;
                const overwriteDesignIdGroup = document.getElementById("productify_group_products_overwrite_with_csv_design_id_group").value;
                const overwriteSize = document.getElementById("productify_group_products_overwrite_with_csv_size").value;
                const overwriteColor = document.getElementById("productify_group_products_overwrite_with_csv_color").value;
                
                const file = fileInput.files[0];
                const resultDiv = document.getElementById("csv-upload-result");
                const loadingDiv = document.getElementById("upload-loading");

                if (!file) {
                    resultDiv.innerHTML = "<div style=\"color: red;\">Please select a CSV file first.</div>";
                    return;
                }

                var formData = new FormData();
                formData.append("csv_file", file);
                formData.append("overwrite_design_id", overwriteDesignId);
                formData.append("overwrite_design_id_group", overwriteDesignIdGroup);
                formData.append("overwrite_size", overwriteSize);
                formData.append("overwrite_color", overwriteColor);
                formData.append("form_key", FORM_KEY);

                loadingDiv.style.display = "flex";
                resultDiv.innerHTML = "";

                var xhr = new XMLHttpRequest();
                xhr.open("POST", "' . $uploadUrl . '", true);

                xhr.onload = function() {
                    loadingDiv.style.display = "none";

                    if (xhr.status === 200) {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            resultDiv.innerHTML = "<div style=\"color: green; font-size: 12px; padding: 10px; background: #f0f8f0; border: 1px solid #d4edda;\">" + response.message + "</div>";
                        } else {
                            resultDiv.innerHTML = "<div style=\"color: red; font-size: 12px; padding: 10px; background: #f8f0f0; border: 1px solid #f5c6cb;\">" + response.message + "</div>";
                        }
                    } else {
                        resultDiv.innerHTML = "<div style=\"color: red;\">An error occurred during upload.</div>";
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
