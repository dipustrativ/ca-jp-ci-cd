<?php

declare(strict_types=1);

namespace CarpetAvenue\ChangePayment\Plugin\Order\Email\Container\Template;

use CarpetAvenue\ChangePayment\Helper\Data;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Container\Template;

class ChangePaymentVars
{
    public function __construct(
        protected Data $dataHelper
    )
    {
    }

    public function beforeSetTemplateVars(Template $subject, array $vars)
    {
        /** @var Order $order */
        $order = $vars['order'];
        $method = $order->getPayment()->getMethod();
        if ($method === "evinvoicepayment" && $this->dataHelper->isEnabled()) {
            $vars['is_invoice'] = true;
            $vars['change_payment_url'] = $this->dataHelper->getChangePaymentConfirmationUrl($order);
        }

        return [$vars];
    }
}
