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

namespace CarpetAvenue\Orderify\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;

class Config extends AbstractHelper
{
    public const XML_PATH_REFUND_STATISTIC_ENABLED = 'orderify/fake_customer_statistic/enable';

    public const XML_PATH_REGION_SETTINGS_ENABLED         = 'orderify/region_settings/enable';
    public const XML_PATH_REGION_OPTIONAL_COUNTRIES       = 'orderify/region_settings/optional_countries';
    public const XML_PATH_REGION_JP_SORT_ENABLED          = 'orderify/region_settings/jp_region_id_sort_enabled';

    public const XML_PATH_CANCEL_INVENTORY_REINDEX_ENABLED = 'orderify/cancel_inventory_reindex/enabled';

    public const XML_PATH_FAILED_PAYMENT_EMAIL_ENABLED  = 'orderify/failed_payment_email/enabled';
    public const XML_PATH_FAILED_PAYMENT_EMAIL_SENDER   = 'orderify/failed_payment_email/sender';
    public const XML_PATH_FAILED_PAYMENT_EMAIL_TEMPLATE = 'orderify/failed_payment_email/template';

    public const XML_PATH_ORDER_REMINDER_EMAIL_ENABLED  = 'orderify/order_reminder_email/enabled';
    public const XML_PATH_ORDER_REMINDER_EMAIL_SENDER   = 'orderify/order_reminder_email/sender';
    public const XML_PATH_ORDER_REMINDER_EMAIL_TEMPLATE = 'orderify/order_reminder_email/template';

    public const XML_PATH_ADYEN_AUTO_INVOICE_ENABLED   = 'orderify/adyen_auto_invoice/enabled';
    public const XML_PATH_ADYEN_AUTO_INVOICE_MIN_AGE   = 'orderify/adyen_auto_invoice/min_order_age_minutes';
    public const XML_PATH_ADYEN_AUTO_INVOICE_BATCH     = 'orderify/adyen_auto_invoice/batch_size';

    private const ADYEN_AUTO_INVOICE_BATCH_MAX = 500;

    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    public function isRefundStatisticEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_REFUND_STATISTIC_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isRegionSettingsEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_REGION_SETTINGS_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns array of country codes where state/region is optional.
     *
     * @return string[]
     */
    public function getOptionalRegionCountries($storeId = null): array
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_REGION_OPTIONAL_COUNTRIES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (empty($value)) {
            return [];
        }

        return explode(',', $value);
    }

    public function isJpRegionSortEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_REGION_JP_SORT_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }


    public function isCancelInventoryReindexEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CANCEL_INVENTORY_REINDEX_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isFailedPaymentEmailEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_FAILED_PAYMENT_EMAIL_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getFailedPaymentEmailSender($storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_FAILED_PAYMENT_EMAIL_SENDER,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getFailedPaymentEmailTemplate($storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_FAILED_PAYMENT_EMAIL_TEMPLATE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isOrderReminderEmailEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ORDER_REMINDER_EMAIL_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getOrderReminderEmailSender($storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_ORDER_REMINDER_EMAIL_SENDER,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getOrderReminderEmailTemplate($storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_ORDER_REMINDER_EMAIL_TEMPLATE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isAdyenAutoInvoiceEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ADYEN_AUTO_INVOICE_ENABLED);
    }

    public function getAdyenAutoInvoiceMinAgeMinutes(): int
    {
        return max(1, (int) $this->scopeConfig->getValue(self::XML_PATH_ADYEN_AUTO_INVOICE_MIN_AGE));
    }

    public function getAdyenAutoInvoiceBatchSize(): int
    {
        $batchSize = max(1, (int) $this->scopeConfig->getValue(self::XML_PATH_ADYEN_AUTO_INVOICE_BATCH));

        return min($batchSize, self::ADYEN_AUTO_INVOICE_BATCH_MAX);
    }
}
