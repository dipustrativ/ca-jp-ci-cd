<?php
namespace Evalent\Banner\Block\Adminhtml\Banner\Widget;


class DatePicker extends \Magento\Backend\Block\Template
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
        /** @var \Magento\Framework\Data\Form\Element\Text $input */

        $input = $this->_elementFactory->create("text", ['data' => $element->getData()]);
        $input->setId($element->getId());
        $input->setForm($element->getForm());
        $input->addCustomAttribute('style', 'width: auto');
        $input->setClass('widget-option input-text admin__control-text');
        if ($element->getRequired()) {
            $input->addClass('required-entry');
        }

        $calendarScript = '
        <script>require([
            "jquery",
            "mage/translate",
            "mage/calendar"
            ], function ($, $t) {
              $("#' . $element->getId() . '").calendar({
                changeMonth: true,
                changeYear: true,
                showButtonPanel: true,
                timeInput: true,
                timeFormat: "HH:mm:ss",
                showsTime: true,
                currentText: $t("Go Today"),
                closeText: $t("Close")
              });
            })</script>';
        $element->setData('after_element_html', $input->getElementHtml() . $calendarScript);
        $element->setValue('');

        return $element;
    }
}
