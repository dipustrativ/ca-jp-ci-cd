<?php


declare(strict_types=1);

namespace IXPF\Syncer\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
class Config extends AbstractHelper
{
    const XML_PATH_CRON_JOB_ENABLED = 'ixpfng/cronjob/enabled';
    const  XML_PATH_GENERAL_SHOP_OBJECT_ID = "ixpfng/general/shop_object_id";
    const XML_PATH_WEBHOOK_PRODUCT_ENABLE_JOBS = 'ixpfng/product/enable_webhook';
    const XML_PATH_WEBHOOK_PRODUCT_URL = 'ixpfng/product/webhook_url';
    const XML_PATH_WEBHOOK_PRODUCT_URL_SECRET = 'ixpfng/product/secret_code';

    const XML_PATH_ORDER_CREATE_ENABLED = 'ixpfng_order/order_create/enabled';
    const XML_PATH_ORDER_CREATE_PAYMENT_METHODS = 'ixpfng_order/order_create/payment_methods';
    const XML_PATH_ORDER_CREATE_ALLOW_OVERSELL = 'ixpfng_order/order_create/allow_oversell';
    const XML_PATH_ORDER_UPDATE_ENABLED = 'ixpfng_order/order_update/enabled';
    const XML_PATH_INVOICE_UPDATE_ENABLED = 'ixpfng_order/invoice_update/enabled';
    const XML_PATH_CREDITMEMO_UPDATE_ENABLED = 'ixpfng_order/creditmemo_update/enabled';
    const XML_PATH_SHIPMENT_UPDATE_ENABLED = 'ixpfng_order/shipment_update/enabled';

    const XML_PATH_CUSTOMER_CREATE_ENABLED = 'ixpfng_customer/customer_create/enabled';

    const XML_PATH_SLACK_NOTIFICATION_ENABLED = 'ixpfng_notification/slack/enabled';
    const XML_PATH_SLACK_WEBHOOK_URL = 'ixpfng_notification/slack/webhook_url';

    const XML_PATH_WEBHOOK_DISPATCH_LIMIT       = 'ixpfng/webhook_dispatch/dispatch_limit';
    const XML_PATH_WEBHOOK_PARALLEL_PROCESSING  = 'ixpfng/webhook_dispatch/parallel_processing';
    const XML_PATH_WEBHOOK_PARALLEL_WORKERS     = 'ixpfng/webhook_dispatch/parallel_workers';

    const XML_PATH_PURCHASE_ROOT_CATEGORY_ID    = 'ixpfng_purchase/purchase/root_category_id';

    const DEFAULT_WEBHOOK_DISPATCH_LIMIT    = 50;
    const DEFAULT_WEBHOOK_PARALLEL_WORKERS  = 4;

    /**
     * Root catalog category id (parent of every store root, e.g. "Default Category").
     */
    const DEFAULT_PURCHASE_ROOT_CATEGORY_ID = 2;

    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    public function isCronJobEnabled(): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_CRON_JOB_ENABLED) == 1;
    }

    public function getShopObjectId(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_GENERAL_SHOP_OBJECT_ID);
    }

    public function isProductWebhookEnabled(): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_WEBHOOK_PRODUCT_ENABLE_JOBS) == 1;
    }

    public function getProductWebhookUrl(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_WEBHOOK_PRODUCT_URL);
    }

    public function getProductWebhookSecret(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_WEBHOOK_PRODUCT_URL_SECRET);
    }

    public function isOrderCreateEnabled(): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_ORDER_CREATE_ENABLED) == 1;
    }

    /**
     * When true, the requested-qty stock check is skipped on quote create so any
     * salable product can be added regardless of available qty (admin parity).
     * The product must still exist, be enabled and salable.
     */
    public function isOrderCreateOversellAllowed(): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_ORDER_CREATE_ALLOW_OVERSELL) == 1;
    }

    /**
     * Allowed payment method codes for the order-create API.
     * Empty array = no restriction (return all active methods).
     *
     * @return string[]
     */
    public function getOrderCreatePaymentMethods(?int $storeId = null): array
    {
        $value = (string)$this->scopeConfig->getValue(
            self::XML_PATH_ORDER_CREATE_PAYMENT_METHODS,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value === '' ? [] : array_filter(array_map('trim', explode(',', $value)));
    }

    public function isOrderUpdateEnabled(): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_ORDER_UPDATE_ENABLED) == 1;
    }

    public function isInvoiceUpdateEnabled(): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_INVOICE_UPDATE_ENABLED) == 1;
    }

    public function isCreditMemoUpdateEnabled(): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_CREDITMEMO_UPDATE_ENABLED) == 1;
    }

    public function isShipmentUpdateEnabled(): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_SHIPMENT_UPDATE_ENABLED) == 1;
    }

    public function isCustomerCreateEnabled(): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_CUSTOMER_CREATE_ENABLED) == 1;
    }

    public function isSlackNotificationEnabled(): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_SLACK_NOTIFICATION_ENABLED) == 1;
    }

    public function getSlackWebhookUrl(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_SLACK_WEBHOOK_URL);
    }

    public function getWebhookDispatchLimit(): int
    {
        $value = (int)$this->scopeConfig->getValue(self::XML_PATH_WEBHOOK_DISPATCH_LIMIT);
        return $value > 0 ? $value : self::DEFAULT_WEBHOOK_DISPATCH_LIMIT;
    }

    public function isWebhookParallelProcessingEnabled(): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_WEBHOOK_PARALLEL_PROCESSING) == 1;
    }

    public function getWebhookParallelWorkers(): int
    {
        $value = (int)$this->scopeConfig->getValue(self::XML_PATH_WEBHOOK_PARALLEL_WORKERS);
        return $value > 0 ? $value : self::DEFAULT_WEBHOOK_PARALLEL_WORKERS;
    }

    public function getPurchaseRootCategoryId(): int
    {
        $value = (int)$this->scopeConfig->getValue(self::XML_PATH_PURCHASE_ROOT_CATEGORY_ID);
        return $value > 0 ? $value : self::DEFAULT_PURCHASE_ROOT_CATEGORY_ID;
    }

}
