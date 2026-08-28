<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\Block\Adminhtml\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Class Color
 * Custom color picker field for system configuration
 */
class Color extends Field
{
    /**
     * Add color picker functionality to the field
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $html = $element->getElementHtml();
        $value = $element->getData('value') ?: '#000000';

        $html .= '<style>
            .color-picker-wrapper {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .color-preview {
                width: 30px;
                height: 30px;
                border: 1px solid #ccc;
                border-radius: 3px;
                cursor: pointer;
            }
        </style>';

        $html .= sprintf(
            '<script type="text/javascript">
                require(["jquery", "jquery/ui"], function($){
                    $(document).ready(function() {
                        const inputId = "%s";
                        const $input = $("#" + inputId);
                        
                        // Wrap the input with color picker UI
                        if (!$input.parent().hasClass("color-picker-wrapper")) {
                            $input.wrap("<div class=\"color-picker-wrapper\"></div>");
                            $input.attr("type", "color");
                            $input.css({
                                "width": "300px",
                                "height": "30px",
                                "border": "1px solid #ccc",
                                "border-radius": "3px",
                                "cursor": "pointer"
                            });
                            
                            // Set initial value
                            if ($input.val() === "") {
                                $input.val("#000000");
                            }
                        }
                    });
                });
            </script>',
            $element->getHtmlId()
        );

        return $html;
    }
}
