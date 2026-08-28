<?php

namespace CarpetAvenue\Proforma\Plugin\Block\Adminhtml\Order;

use Magento\Framework\UrlInterface;

class View
{
    public function __construct(
        private UrlInterface $urlBuilder,
        protected \CarpetAvenue\Proforma\Model\Config $config
    ){}

    public function beforeSetLayout(\Magento\Sales\Block\Adminhtml\Order\View $view)
    {
        if (!$this->config->enabled($view->getOrder()->getStoreId())) {
            return;
        }
        $message = 'Are you sure you want to create a proforma invoice?';
        $url = $this->urlBuilder->getUrl(
            'caproforma/order/generate',
            ['id' => $view->getOrderId()]
        );

        if($this->config->enabled($view->getOrder()->getStoreId())){
            $view->addButton(
                'order_generate_proforma',
                [
                    'label' => __('Create Proforma'),
                    'class' => 'action-secondary',
                    'onclick' => "confirmSetLocation('{$message}', '{$url}')"
                ]
            );
        }
    }
}
