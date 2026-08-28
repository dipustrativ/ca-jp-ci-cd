<?php
declare(strict_types=1);

namespace CarpetAvenue\Widget\Block\Adminhtml\Widget;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\Element\Factory as ElementFactory;

class Color extends \Magento\Backend\Block\Template
{
    protected ElementFactory $_elementFactory;

    public function __construct(
        Context        $context,
        ElementFactory $elementFactory,
        array          $data = []
    ) {
        $this->_elementFactory = $elementFactory;
        parent::__construct($context, $data);
    }

    public function prepareElementHtml(AbstractElement $element): AbstractElement
    {
        $value = $element->getData('value') ? $element->getData('value') : $this->getData('value');
        $input = $this->_elementFactory->create("text", ['data' => $element->getData()]);
        $input->setId($element->getId());
        $input->setForm($element->getForm());
        $input->setValue($element->getData('value') ? $element->getData('value') : $this->getData('value'));
        $input->setClass("widget-option input-textarea admin__control-text");
        if ($element->getRequired()) {
            $input->addClass('required-entry');
        }

        $html = '<script type="text/javascript">
        require(["jquery","jquery/colorpicker/js/colorpicker"], function ($) {
            $(document).ready(function () {
                var $el = $("#' . $element->getHtmlId() . '");
                $el.css("backgroundColor", "'. $value .'");
                $el.ColorPicker({
                    color: "'. $value .'",
                    onChange: function (hsb, hex, rgb) {
                        $el.css("backgroundColor", "#" + hex).val("#" + hex);
                    }
                });
            });
        });
        </script>';

        $element->setData('after_element_html', $input->getElementHtml() . $html);
        return $element;
    }
}
