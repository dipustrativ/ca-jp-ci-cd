<?php

namespace Evalent\OrderMods\Plugin;

use Magento\Sales\Block\Adminhtml\Order\View;

class AddButtonsToOrder
{
    const ENABLED_PAYMENTS = 'sales_email/payment_method_for_reminder_and_cancellation/payment_method';

    private View $view;
    private $orderReminderIdentity;
    private $orderCancellationIdentity;
    private $scopeConfig;

    public function __construct(
        View $view,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Evalent\OrderMods\Model\Order\Email\Container\OrderReminderIdentity $orderReminderIdentity,
        \Evalent\OrderMods\Model\Order\Email\Container\OrderCancellationIdentity $orderCancellationIdentity
    )
    {
        $this->view = $view;
        $this->orderReminderIdentity = $orderReminderIdentity;
        $this->orderCancellationIdentity = $orderCancellationIdentity;
        $this->scopeConfig = $scopeConfig;
    }

    public function beforeSetLayout(\Magento\Sales\Block\Adminhtml\Order\View $view)
    {
        if($selectedPayments = $this->scopeConfig->getValue(self::ENABLED_PAYMENTS, \Magento\Store\Model\ScopeInterface::SCOPE_STORE)){
            $arrayPayments = explode(',', $selectedPayments);
        }
        else{
            $arrayPayments = [];
        }
        $order = $view->getOrder();
        if (in_array($order->getPayment()->getMethod(), $arrayPayments) && $order->canCancel()) {
            if ($this->orderReminderIdentity->isEnabled($order->getStoreId())) {
                $messageReminder ='Are you sure you want to remind customer of order?';
                $urlReminder = 'remind_customer/order/reminder';

                $view->addButton(
                    'order_button_reminder',
                    [
                        'label' => __('Send Order Reminder'),
                        'class' => 'order_button_reminder',
                        'onclick' => "confirmSetLocation('{$messageReminder}', '{$this->view->getUrl($urlReminder)}')"
                    ],
                    -1
                );
            }

            if ($this->orderCancellationIdentity->isEnabled()) {
                $messageCancellation = 'Are you sure you want to remind user of cancellation order?';
                $urlCancellation = 'remind_customer/order/cancellation';
                $view->addButton(
                    'send_order_cancellation_email',
                    [
                        'label' => __('Cancel order & Send email'),
                        'class' => 'send_order_cancellation_email',
                        'onclick' => "confirmSetLocation('{$messageCancellation}', '{$this->view->getUrl($urlCancellation)}')"
                    ],
                    -1
                );

                $view->updateButton('order_cancel', 'label', __('Cancel order & No email'));
            }
        }
    }
}
