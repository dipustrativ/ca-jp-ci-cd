<?php namespace Evalent\Banner\Block\Adminhtml\Banner\Widget;

class Color extends \Magento\Backend\Block\Template
{
    /**
     * @var \Magento\Framework\Data\Form\Element\Factory
     */
    protected $_elementFactory;
    /**
     * @param \Magento\Backend\Block\Template\Context $context
     * @param \Magento\Framework\Data\Form\Element\Factory $elementFactory
     * @param array $data
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Framework\Data\Form\Element\Factory $elementFactory,
        array $data = []
    ) {
        $this->_elementFactory = $elementFactory;
        parent::__construct($context, $data);
    }
    /**
     * Prepare chooser element HTML
     *
     * @param \Magento\Framework\Data\Form\Element\AbstractElement $element Form Element
     * @return \Magento\Framework\Data\Form\Element\AbstractElement
     */
    public function prepareElementHtml(\Magento\Framework\Data\Form\Element\AbstractElement $element)
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

                // Attach the color picker
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
