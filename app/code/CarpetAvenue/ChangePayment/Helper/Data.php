<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace CarpetAvenue\ChangePayment\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Url;
use Magento\Sales\Api\Data\OrderInterface;

class Data extends AbstractHelper
{

    /**
     * @param Context $context
     */
    public function __construct(
        Context             $context,
        protected Url       $url,
        protected Json      $json,
        protected Encryptor $encryptor
    )
    {
        parent::__construct($context);
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        if ($this->scopeConfig->isSetFlag('sales/change_payment/enabled')) {
            return true;
        }
        return false;
    }

    public function getChangePaymentUrl(OrderInterface $order): string
    {
        $urlParams = [
            'customer_id' => $order->getCustomerId(),
            'email' => $order->getCustomerEmail(),
            'order_id' => $order->getId()
        ];
        $urlParams = $this->json->serialize($urlParams);
        return $this->url->getUrl(
            'change_payment/payment/change',
            ['params' => $this->encryptor->encrypt($urlParams)]
        );
    }

    public function getChangePaymentConfirmationUrl(OrderInterface $order): string
    {
        $urlParams = [
            'customer_id' => $order->getCustomerId(),
            'email' => $order->getCustomerEmail(),
            'order_id' => $order->getId()
        ];
        $urlParams = $this->json->serialize($urlParams);
        return $this->url->getUrl(
            'change_payment/payment/confirmation',
            ['params' => $this->encryptor->encrypt($urlParams)]
        );
    }
    public function getRevertOriginalPaymentAfter(): int
    {
        $value = $this->scopeConfig->getValue('sales/change_payment/revert_original_payment_after');
        if ($value && is_numeric($value)) {
            return (int)$value;
        }
        return 30; // Default to 30 minutes if not set
    }
    public function getAllowReorderForOrderStatus(): array
    {
        $statuses = $this->scopeConfig->getValue('sales/change_payment/allow_reorder_for_order_status');
        if ($statuses) {
            return explode(',', $statuses);
        }
        return [];
    }
    public function getCartUrl(): string
    {
        return $this->url->getUrl('checkout/cart');
    }
}


