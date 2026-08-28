<?php

namespace Evalent\ProductCertificate\Plugin\Block\Adminhtml\Order;

use Magento\Framework\UrlInterface;

class View
{
    /**
     * @var UrlInterface
     */
    private $_urlBuilder;

    public function __construct(UrlInterface $urlBuilder)
    {
        $this->_urlBuilder = $urlBuilder;
    }

    public function beforeSetLayout(\Magento\Sales\Block\Adminhtml\Order\View $view)
    {
        $message = 'Are you sure you want to download the certificates?';
        $url = $this->_urlBuilder->getUrl(
            'evalent_coa/order/generate',
            ['id' => $view->getOrderId()]
        );

        $view->addButton(
            'order_generate_coa',
            [
                'label' => __('Download Certificates'),
                'class' => 'action-secondary',
                'onclick' => "confirmSetLocation('{$message}', '{$url}')"
            ]
        );
    }
}
