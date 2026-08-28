<?php
declare(strict_types=1);

namespace CarpetAvenue\Widget\Block\Adminhtml\Widget;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\Element\Factory;

class ImageChooser extends \Magento\Backend\Block\Template
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
        $config = $this->_getData('config');
        $sourceUrl = $this->getUrl('cms/wysiwyg_images/index', ['target_element_id' => $element->getId(), 'type' => 'file']);

        $chooser = $this->getLayout()->createBlock('Magento\Backend\Block\Widget\Button')
            ->setType('button')
            ->setClass('btn-chooser')
            ->setLabel($config['button']['open'])
            ->setOnClick('MediabrowserUtility.openDialog(\'' . $sourceUrl . '\',"MediaBrowser", {})')
            ->setDisabled($element->getReadonly());

        $input = $this->_elementFactory->create("text", ['data' => $element->getData()]);
        $input->setId($element->getId());
        $input->setForm($element->getForm());
        $input->setClass("widget-option input-text admin__control-text");
        $input->addCustomAttribute('data-force_static_path', 1);

        if ($element->getRequired()) {
            $input->addClass('required-entry');
        }

        $element->setData(
            'after_element_html',
            '<img src="' . $element->getValue() . '" style="width: 120px; height: auto;" />'
            . $input->getElementHtml()
            . $chooser->toHtml()
            . "<script>require(['mage/adminhtml/browser']);</script>"
        );

        return $element;
    }
}
