<?php

declare(strict_types=1);

namespace Evalent\OrderMods\Block\Adminhtml\Order\Creditmemo\Create;

class UpdateQtyButton extends \Magento\Sales\Block\Adminhtml\Order\Creditmemo\Create\Items
{

    protected function _prepareLayout()
    {
        $onclick = "submitAndReloadArea($('creditmemo_item_container'),'" . $this->getUpdateUrl() . "')";
        $this->addChild(
            'update_totals_button',
            \Magento\Backend\Block\Widget\Button::class,
            ['label' => __('Update Totals'), 'class' => '', 'onclick' => $onclick]
        );
    }
}