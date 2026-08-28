<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Block\Adminhtml\Order;

use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;

class RefundStatisticsSection extends Template
{
    public function __construct(
        Template\Context $context,
        protected Registry $_registry,
        array $data = []
    )
    {
        parent::__construct($context, $data);
    }

    /**
     * @return mixed|null
     */
    public function getOrder(): mixed
    {
        return $this->_registry->registry('current_order');
    }

    /**
     * Get admin URL for AJAX
     */
    public function getAjaxUrl(): string
    {
        return $this->getUrl('orderify/order/refundstatistics', [
            'order_id' => $this->getOrder()?->getId()
        ]);
    }
}