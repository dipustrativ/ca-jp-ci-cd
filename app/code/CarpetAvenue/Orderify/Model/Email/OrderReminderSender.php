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

namespace CarpetAvenue\Orderify\Model\Email;

use CarpetAvenue\Orderify\Helper\Config;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address\Renderer as AddressRenderer;
use Magento\Store\Model\Information as StoreInformation;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends the "pay for your order" reminder for orders still awaiting payment.
 *
 * Same delivery style as FailedPaymentSender (TransportBuilder + orderify
 * config paths); the template vars mirror the stock order email so the
 * sales_email_order_items layout handle renders inside the template.
 */
class OrderReminderSender
{
    private const XML_PATH_SUPPORT_EMAIL = 'trans_email/ident_support/email';

    public function __construct(
        private readonly Config                $config,
        private readonly TransportBuilder      $transportBuilder,
        private readonly StateInterface        $inlineTranslation,
        private readonly StoreManagerInterface $storeManager,
        private readonly StoreInformation      $storeInformation,
        private readonly ScopeConfigInterface  $scopeConfig,
        private readonly AddressRenderer       $addressRenderer,
        private readonly PaymentHelper         $paymentHelper,
        private readonly LoggerInterface       $logger
    ) {
    }

    /**
     * Send the reminder email to the order's customer.
     *
     * @param Order $order
     * @return bool True when the email was handed to the transport.
     */
    public function send(Order $order): bool
    {
        $storeId = (int) $order->getStoreId();

        if (!$this->config->isOrderReminderEmailEnabled($storeId)) {
            return false;
        }

        $customerEmail = $order->getCustomerEmail();
        if (!$customerEmail) {
            return false;
        }

        try {
            $this->inlineTranslation->suspend();

            $store = $this->storeManager->getStore($storeId);

            $transport = $this->transportBuilder
                ->setTemplateIdentifier($this->config->getOrderReminderEmailTemplate($storeId))
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars([
                    'order'                    => $order,
                    'order_id'                 => $order->getId(),
                    'store'                    => $store,
                    'store_url'                => $store->getBaseUrl(),
                    'billing'                  => $order->getBillingAddress(),
                    'formattedBillingAddress'  => $this->getFormattedBillingAddress($order),
                    'formattedShippingAddress' => $this->getFormattedShippingAddress($order),
                    'payment_html'             => $this->getPaymentHtml($order, $storeId),
                    'created_at_formatted'     => $order->getCreatedAtFormatted(2),
                    'shipping_description'     => $order->getShippingDescription(),
                    'order_data'               => [
                        'customer_name'         => $order->getCustomerName(),
                        'frontend_status_label' => $order->getFrontendStatusLabel(),
                        'order_created_at'      => substr((string) $order->getCreatedAt(), 0, 10),
                        'increment_id'          => $order->getIncrementId(),
                        'phone'                 => $this->getStorePhone($storeId),
                        'email'                 => $this->getSupportEmail($storeId),
                        'is_not_virtual'        => $order->getIsNotVirtual(),
                        'email_customer_note'   => $order->getEmailCustomerNote(),
                    ],
                ])
                ->setFromByScope($this->config->getOrderReminderEmailSender($storeId), $storeId)
                ->addTo($customerEmail, $order->getCustomerName())
                ->getTransport();

            $transport->sendMessage();
            $this->inlineTranslation->resume();

            return true;
        } catch (\Exception $e) {
            $this->inlineTranslation->resume();
            $this->logger->error(sprintf(
                '[Orderify] Order reminder error for order #%s: %s',
                $order->getIncrementId(),
                $e->getMessage()
            ));

            return false;
        }
    }

    /**
     * Rendered billing address, or empty string when absent.
     *
     * @param Order $order
     * @return string
     */
    private function getFormattedBillingAddress(Order $order): string
    {
        $address = $order->getBillingAddress();

        return $address ? (string) $this->addressRenderer->format($address, 'html') : '';
    }

    /**
     * Rendered shipping address, or empty string for virtual orders.
     *
     * @param Order $order
     * @return string
     */
    private function getFormattedShippingAddress(Order $order): string
    {
        if ($order->getIsVirtual()) {
            return '';
        }

        $address = $order->getShippingAddress();

        return $address ? (string) $this->addressRenderer->format($address, 'html') : '';
    }

    /**
     * Payment info block as html.
     *
     * @param Order $order
     * @param int $storeId
     * @return string
     */
    private function getPaymentHtml(Order $order, int $storeId): string
    {
        $payment = $order->getPayment();
        if (!$payment) {
            return '';
        }

        return (string) $this->paymentHelper->getInfoBlockHtml($payment, $storeId);
    }

    /**
     * Store phone number from Stores > Configuration > General > Store Information.
     *
     * @param int $storeId
     * @return string
     */
    private function getStorePhone(int $storeId): string
    {
        try {
            $store = $this->storeManager->getStore($storeId);

            return (string) $this->storeInformation->getStoreInformationObject($store)->getPhone();
        } catch (\Exception) {
            return '';
        }
    }

    /**
     * Support email address for the store.
     *
     * @param int $storeId
     * @return string
     */
    private function getSupportEmail(int $storeId): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_SUPPORT_EMAIL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
