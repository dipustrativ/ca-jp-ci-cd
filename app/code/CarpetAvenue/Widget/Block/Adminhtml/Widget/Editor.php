<?php
declare(strict_types=1);

namespace CarpetAvenue\Widget\Block\Adminhtml\Widget;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\Element\Factory;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

class Editor extends \Magento\Backend\Block\Template
{
    const TEXT_AREA_ID = 'contentWidget';

    protected Factory $_elementFactory;

    protected mixed $secureRenderer;

    public function __construct(
        Context $context,
        Factory $elementFactory,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null
    ) {
        $this->secureRenderer = $secureRenderer ?? ObjectManager::getInstance()->get(SecureHtmlRenderer::class);
        $this->_elementFactory = $elementFactory;
        parent::__construct($context, $data);
    }

    public function prepareElementHtml(AbstractElement $element): AbstractElement
    {
        $input = $this->_elementFactory->create("textarea", ['data' => $element->getData()]);
        $input->setId(self::TEXT_AREA_ID);
        $input->setForm($element->getForm());
        $input->setClass("widget-option input-textarea admin__control-text");
        if ($element->getRequired()) {
            $input->addClass('required-entry');
        }

        $element->setData('after_element_html', $input->getElementHtml() . $this->addAfterHtmlJs() . $this->getAfterElementHtmlCss());
        return $element;
    }

    public function addAfterHtmlJs(): string
    {
        $scriptString = <<<HTML
                require([
                    'jquery',
                    'mage/adminhtml/wysiwyg/tiny_mce/setup'
                ], function(jQuery){
                    wysiwygcompany_description = new wysiwygSetup('{$this->getHtmlId()}', {
                            "width":"100%%",
                            "height":"200px",
                            "plugins":[],
                            "tinymce4":{"toolbar":"formatselect | bold italic underline | alignleft aligncenter alignright | bullist numlist | image link table charmap","plugins":"advlist autolink lists link charmap image media noneditable table contextmenu paste code help table",
                         }
                     });
                     wysiwygcompany_description.setup("exact");
                });
            HTML;
        return $this->secureRenderer->renderTag('script', [], $scriptString, false);
    }

    protected function getAfterElementHtmlCss(): string
    {
        return <<<HTML
            <style>
                .admin__field-control.control .control-value {
                    display: none !important;
                }
            </style>
            HTML;
    }

    public function getHtmlId(): string
    {
        return self::TEXT_AREA_ID;
    }
}
