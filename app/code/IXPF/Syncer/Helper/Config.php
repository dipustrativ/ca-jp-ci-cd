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

    const XML_PATH_RESERVATION_ENABLED      = 'ixpfng_reservation/general/enabled';
    const XML_PATH_RESERVATION_INBOUND_ENABLED = 'ixpfng_reservation/general/inbound_enabled';
    const XML_PATH_RESERVATION_AUTO_ASSIGN_SOURCE = 'ixpfng_reservation/general/auto_assign_source';
    const XML_PATH_RESERVATION_MAX_ATTEMPTS = 'ixpfng_reservation/general/max_attempts';
    const XML_PATH_RESERVATION_DISPATCH_LIMIT = 'ixpfng_reservation/general/dispatch_limit';

    const XML_PATH_REVIEWS_ENABLED          = 'ixpf_reviews/general/enabled';
    const XML_PATH_REVIEWS_SHOW_ON_FRONTEND = 'ixpf_reviews/general/show_on_frontend';
    const XML_PATH_REVIEWS_PER_PAGE         = 'ixpf_reviews/general/per_page';
    const XML_PATH_REVIEWS_WARN_THRESHOLD   = 'ixpf_reviews/general/warn_threshold';
    const XML_PATH_REVIEWS_MEDIA_ENABLED    = 'ixpf_reviews/media/enabled';

    const DEFAULT_WEBHOOK_DISPATCH_LIMIT    = 50;
    const DEFAULT_WEBHOOK_PARALLEL_WORKERS  = 4;

    const DEFAULT_RESERVATION_MAX_ATTEMPTS   = 10;
    const DEFAULT_RESERVATION_DISPATCH_LIMIT = 50;

    const DEFAULT_REVIEWS_PER_PAGE          = 10;
    const DEFAULT_REVIEWS_WARN_THRESHOLD    = 2000;

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

    /**
     * Master switch for the outbound reservation webhook (placement and lifecycle).
     * Off by default — nothing is sent until it is turned on deliberately.
     */
    public function isReservationEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_RESERVATION_ENABLED);
    }

    /**
     * Whether the inbound endpoint may write source item quantities.
     *
     * Separate from the outbound switch on purpose: IXPF can be allowed to push
     * adjustments before we start emitting reservations, and vice versa.
     */
    public function isReservationInboundEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_RESERVATION_INBOUND_ENABLED);
    }

    /**
     * Whether an inbound adjustment may create the source item it needs.
     *
     * IXPF is the system of record for which warehouse holds a carpet, so a SKU
     * it names at a source it does not yet have there is normally a link Magento
     * is missing rather than a bad request. With this on, the link is created at
     * qty 0 and the adjustment applied on top; the reservation row says so in
     * `information`. The source itself is never invented — only the sku-source
     * assignment.
     */
    public function isReservationAutoAssignSourceEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_RESERVATION_AUTO_ASSIGN_SOURCE);
    }

    /**
     * Give-up threshold for a reservation batch.
     */
    public function getReservationMaxAttempts(): int
    {
        $value = (int)$this->scopeConfig->getValue(self::XML_PATH_RESERVATION_MAX_ATTEMPTS);
        return $value > 0 ? $value : self::DEFAULT_RESERVATION_MAX_ATTEMPTS;
    }

    /**
     * Batches a single cron tick will send.
     */
    public function getReservationDispatchLimit(): int
    {
        $value = (int)$this->scopeConfig->getValue(self::XML_PATH_RESERVATION_DISPATCH_LIMIT);
        return $value > 0 ? $value : self::DEFAULT_RESERVATION_DISPATCH_LIMIT;
    }

    /**
     * Master switch for the review read service and cleanup cron.
     * Ingest is deliberately NOT behind this — IXPF backfills while everything downstream is off.
     */
    public function isReviewsEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_REVIEWS_ENABLED,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Whether reviews are shown to customers.
     *
     * Deliberately ANDed with the master switch rather than checked on its own: with the master
     * off the read service returns zeros, so honouring this flag alone would render a trigger
     * that opens an empty drawer.
     */
    public function isReviewsFrontendEnabled(?int $storeId = null): bool
    {
        return $this->isReviewsEnabled($storeId)
            && $this->scopeConfig->isSetFlag(
                self::XML_PATH_REVIEWS_SHOW_ON_FRONTEND,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $storeId
            );
    }

    public function getReviewsPerPage(?int $storeId = null): int
    {
        $value = (int)$this->scopeConfig->getValue(
            self::XML_PATH_REVIEWS_PER_PAGE,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $value > 0 ? $value : self::DEFAULT_REVIEWS_PER_PAGE;
    }

    /**
     * Review count in one payload above which the push is processed anyway but logged as a
     * warning. That number is the early signal for the payload-size problem.
     */
    public function getReviewWarnThreshold(): int
    {
        $value = (int)$this->scopeConfig->getValue(self::XML_PATH_REVIEWS_WARN_THRESHOLD);
        return $value > 0 ? $value : self::DEFAULT_REVIEWS_WARN_THRESHOLD;
    }

    public function isReviewMediaEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_REVIEWS_MEDIA_ENABLED);
    }
}
