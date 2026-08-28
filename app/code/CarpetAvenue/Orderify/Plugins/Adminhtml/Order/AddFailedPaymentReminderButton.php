<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Order
 * @package   CarpetAvenue_Orderify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Plugins\Adminhtml\Order;

use CarpetAvenue\Orderify\Helper\Config;
use Magento\Sales\Block\Adminhtml\Order\View;
use Magento\Sales\Model\Order;

class AddFailedPaymentReminderButton
{
    public function __construct(
        private readonly Config $config
    ) {}

    public function beforeSetLayout(View $view): void
    {
        $order   = $view->getOrder();
        $storeId = (int) $order->getStoreId();

        if (!$this->config->isFailedPaymentEmailEnabled($storeId)) {
            return;
        }

        if ($order->getStatus() !== Order::STATE_CANCELED) {
            return;
        }

        $message = __('Are you sure you want to send a failed payment reminder to the customer?');
        $url     = $view->getUrl('orderify/order/sendFailedPaymentReminder', ['order_id' => $order->getId()]);

        $view->addButton(
            'orderify_send_failed_payment_reminder',
            [
                'label'   => __('Send Failed Payment Reminder'),
                'class'   => 'orderify-failed-payment-reminder',
                'onclick' => "confirmSetLocation('{$message}', '{$url}')",
            ],
            -1
        );
    }
}
