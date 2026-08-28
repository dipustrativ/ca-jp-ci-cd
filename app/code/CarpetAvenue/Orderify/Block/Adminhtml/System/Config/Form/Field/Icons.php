<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Block\Adminhtml\System\Config\Form\Field;

use CarpetAvenue\Orderify\Model\PaymentIcon\IconRepository;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\FormKey;

/**
 * Dynamic-rows config field for the "we accept" icon strip.
 *
 * Each row is a label + an icon path picked through a media chooser
 * (browse existing / upload new). Stored as a serialized array by
 * Magento\Config\Model\Config\Backend\Serialized\ArraySerialized.
 */
class Icons extends AbstractFieldArray
{
    /** @var IconColumn */
    private $iconRenderer;

    /** @var FormKey */
    private $formKeyProvider;

    /** @var IconRepository */
    private $iconRepository;

    public function __construct(
        Context $context,
        FormKey $formKey,
        IconRepository $iconRepository,
        array $data = []
    ) {
        $this->formKeyProvider = $formKey;
        $this->iconRepository = $iconRepository;
        parent::__construct($context, $data);
    }

    /**
     * @return void
     */
    protected function _prepareToRender()
    {
        $this->addColumn('label', [
            'label' => __('Label'),
            'class' => 'input-text',
            'style' => 'width:160px',
        ]);
        $this->addColumn('icon', [
            'label' => __('Icon'),
            'renderer' => $this->getIconRenderer(),
        ]);
        $this->_addAfter = false;
        $this->_addButtonLabel = (string)__('Add icon');
    }

    /**
     * @return IconColumn
     */
    private function getIconRenderer(): IconColumn
    {
        if (!$this->iconRenderer) {
            $this->iconRenderer = $this->getLayout()->createBlock(
                IconColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->iconRenderer;
    }

    /**
     * Append the chooser bootstrap (urls + form key) once after the grid.
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return parent::_getElementHtml($element) . $this->getChooserInitHtml();
    }

    /**
     * @return string
     */
    private function getChooserInitHtml(): string
    {
        $config = [
            'listingUrl' => $this->getUrl('orderify/icon/listing'),
            'uploadUrl' => $this->getUrl('orderify/icon/upload'),
            'mediaBaseUrl' => $this->iconRepository->getMediaBaseUrl(),
            'formKey' => $this->formKeyProvider->getFormKey(),
        ];
        $init = ['*' => ['CarpetAvenue_Orderify/js/payment-icon-chooser' => $config]];

        return '<script type="text/x-magento-init">'
            . json_encode($init, JSON_UNESCAPED_SLASHES)
            . '</script>';
    }
}
