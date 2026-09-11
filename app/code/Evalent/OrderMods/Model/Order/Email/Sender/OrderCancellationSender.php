<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Evalent\OrderMods\Model\Order\Email\Sender;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Sales\Model\Order;
use Evalent\OrderMods\Model\Order\Email\Container\OrderCancellationIdentity;
use Magento\Sales\Model\Order\Email\Container\Template;
use Magento\Sales\Model\Order\Email\NotifySender;
use Magento\Sales\Model\Order\Address\Renderer;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\DataObject;
use Magento\Sales\Model\Order\Email\SenderBuilderFactory;
use Magento\Store\Model\Information;
use Psr\Log\LoggerInterface;

/**
 * Class OrderCommenterSender
 */
class OrderCancellationSender extends NotifySender
{
    /**
     * @var Renderer
     */
    protected $addressRenderer;

    /**
     * Application Event Dispatcher
     *
     * @var ManagerInterface
     */
    protected $eventManager;

    private $storeInfo;

    private $scopeConfig;

    private $paymentHelper;

    /**
     * @param Template $templateContainer
     * @param OrderCancellationIdentity $identityContainer
     * @param SenderBuilderFactory $senderBuilderFactory
     * @param LoggerInterface $logger
     * @param Renderer $addressRenderer
     * @param ManagerInterface $eventManager
     */
    public function __construct(
        Template $templateContainer,
        OrderCancellationIdentity $identityContainer,
        SenderBuilderFactory $senderBuilderFactory,
        LoggerInterface $logger,
        Renderer $addressRenderer,
        ManagerInterface $eventManager,
        Information $storeInfo,
        ScopeConfigInterface $scopeConfig,
        PaymentHelper $paymentHelper
    ) {
        parent::__construct($templateContainer, $identityContainer, $senderBuilderFactory, $logger, $addressRenderer);
        $this->addressRenderer = $addressRenderer;
        $this->eventManager = $eventManager;
        $this->storeInfo = $storeInfo;
        $this->scopeConfig = $scopeConfig;
        $this->paymentHelper = $paymentHelper;
    }

    /**
     * Send email to customer
     *
     * @param Order $order
     * @param bool $notify
     * @param string $comment
     * @return bool
     */
    public function send(Order $order, $notify = true, $comment = '')
    {
        $this->identityContainer->setStore($order->getStore());

        $transport = [
            'order' => $order,
            'comment' => $comment,
            'billing' => $order->getBillingAddress(),
            'store' => $order->getStore(),
            'formattedShippingAddress' => $this->getFormattedShippingAddress($order),
            'formattedBillingAddress' => $this->getFormattedBillingAddress($order),
            'order_id' => $order->getId(),
            'payment_html' => $this->getPaymentHtml($order),
            'created_at_formatted' => $order->getCreatedAtFormatted(2),
            'shipping_description' => $order->getShippingDescription(),
            'order_data' => [
                'customer_name' => $order->getCustomerName(),
                'frontend_status_label' => $order->getFrontendStatusLabel(),
                'order_created_at' => substr($order->getCreatedAt(), 0, 10),
                'increment_id' => $order->getIncrementId(),
                'phone' => $this->storeInfo->getStoreInformationObject($order->getStore())->getPhone(),
                'email' => $this->scopeConfig->getValue('trans_email/ident_support/email', \Magento\Store\Model\ScopeInterface::SCOPE_STORE),
                'is_not_virtual' => $order->getIsNotVirtual(),
                'email_customer_note' => $order->getEmailCustomerNote()
            ]
        ];
        $transportObject = new DataObject($transport);

        /**
         * Event argument `transport` is @deprecated. Use `transportObject` instead.
         */
        $this->eventManager->dispatch(
            'email_order_comment_set_template_vars_before',
            ['sender' => $this, 'transport' => $transportObject->getData(), 'transportObject' => $transportObject]
        );

        $this->templateContainer->setTemplateVars($transportObject->getData());

        return $this->checkAndSend($order, $notify);
    }

    /**
     * Get payment info block as html
     *
     * @param Order $order
     * @return string
     */
    protected function getPaymentHtml(Order $order)
    {
        return $this->paymentHelper->getInfoBlockHtml(
            $order->getPayment(),
            $this->identityContainer->getStore()->getStoreId()
        );
    }
}
