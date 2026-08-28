<?php
declare(strict_types=1);

namespace CarpetAvenue\Widget\Block\Adminhtml\Widget;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\Element\Factory;

class DatePicker extends \Magento\Backend\Block\Template
{
    protected Factory $_elementFactory;

    public function __construct(
        Context $context,
        Factory $elementFactory,
        array $data = []
    ) {
        $this->_elementFactory = $elementFactory;
        parent::__construct($context, $data);
    }

    public function prepareElementHtml(AbstractElement $element): AbstractElement
    {
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
