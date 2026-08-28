<?php

namespace IXPF\Syncer\Block\Adminhtml\Button\Webhook\Product;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class Button extends Field
{
    public function _getElementHtml(AbstractElement $element): string
    {
        /**
         * @var \Magento\Backend\Block\Widget\Button $buttonBlock
         */
        $buttonBlock = $this->getForm()
            ->getLayout()
            ->createBlock('Magento\Backend\Block\Widget\Button');

        $url = $this->getUrl("ixpfsyncer/webhook/manualtrigger");
        $data = [
            'class'   => 'webhook-job-manual-trigger-admin-button',
            'label'   => __('Run Webhook Manually'),
            'onclick' => "setLocation('" . $url . "')",
        ];
        return $buttonBlock->setData($data)->toHtml();
    }
}
