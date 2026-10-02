<?php

namespace CarpetAvenue\ChangePayment\Plugin\Block\Adminhtml\Order;

use Magento\Framework\UrlInterface;

class View
{
    public function __construct(
        private UrlInterface $urlBuilder,
        protected \CarpetAvenue\Proforma\Model\Config $config,
        protected \CarpetAvenue\ChangePayment\Helper\Data $dataHelper,
        protected \CarpetAvenue\ChangePayment\Helper\Order $orderHelper
    ){}

    public function beforeSetLayout(\Magento\Sales\Block\Adminhtml\Order\View $view)
    {
        if (!$this->dataHelper->isEnabled()) {
            return;
        }
        if (!$this->orderHelper->canHoldAndReorder($view->getOrder())) {
            return;
        }
        $url = $this->dataHelper->getChangePaymentConfirmationUrl($view->getOrder());

        $view->addButton(
            'order_clone',
            [
                'label' => __('Clone Order'),
                'class' => 'action-secondary',
                'id' => 'order-view-clone-button',
                'onclick' => "window.open('{$url}')"
            ]
        );
    }
}
