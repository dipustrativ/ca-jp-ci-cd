<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Block\Adminhtml\System\Config\Form\Field;

use Magento\Backend\Block\Widget\Button;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\FormKey;

/**
 * Renders the "Update Payment Icons" button in system config.
 *
 * The button POSTs to orderify/icon/update which rebuilds every listed store
 * view's icon strip from the configured Google Sheet.
 */
class UpdateButton extends Field
{
    /** @var FormKey */
    private $formKeyProvider;

    public function __construct(
        Context $context,
        FormKey $formKey,
        array $data = []
    ) {
        $this->formKeyProvider = $formKey;
        parent::__construct($context, $data);
    }

    /**
     * Remove the scope ("Use Website/Default") controls — this is an action, not a value.
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $button = $this->getLayout()->createBlock(Button::class)
            ->setData([
                'id' => 'orderify_payment_icons_update',
                'label' => __('Update Payment Icons from Sheet'),
            ]);

        $config = [
            'updateUrl' => $this->getUrl('orderify/icon/update'),
            'formKey' => $this->formKeyProvider->getFormKey(),
        ];
        $init = ['*' => ['CarpetAvenue_Orderify/js/payment-icon-updater' => $config]];

        return $button->toHtml()
            . '<span id="orderify_payment_icons_update_result" class="orderify-update-result"></span>'
            . '<script type="text/x-magento-init">'
            . json_encode($init, JSON_UNESCAPED_SLASHES)
            . '</script>';
    }
}
