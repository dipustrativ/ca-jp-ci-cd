<?php

namespace IXPF\Syncer\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;

class ProductPurchase extends AbstractHelper
{
    const  XML_PATH_GENERAL_SHOP_OBJECT_ID = "ixpfng/general/shop_object_id";
    const XML_PATH_IXPFNG_PRODUCT_PURCHASE_ENABLE = "ixpfng_purchase/purchase/enable";
    const XML_PATH_IXPFNG_PRODUCT_PURCHASE_REQUIRED_ATTRIBUTES = "ixpfng_purchase/purchase/required_attributes";
    const XML_PATH_IXPFNG_PRODUCT_PURCHASE_WEBHOOK_ENABLE = "ixpfng_purchase/webhook/enable_webhook";
    const XML_PATH_IXPFNG_PRODUCT_PURCHASE_WEBHOOK_URL = "ixpfng_purchase/webhook/webhook_url";
    const XML_PATH_IXPFNG_PRODUCT_PURCHASE_WEBHOOK_SECRET = "ixpfng_purchase/webhook/secret_code";
    const XML_PATH_IXPFNG_PRODUCT_PURCHASE_CRON_ENABLE = "ixpfng_purchase/cronjob/enabled";

    public function __construct(
        Context $context,
        protected Attribute $attribute,
        protected Product $product
    )
    {
        parent::__construct($context);
    }

    /**
     * @return bool
     */
    public function isEnablePurchaseProduct(): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_IXPFNG_PRODUCT_PURCHASE_ENABLE) == 1;
    }

    /**
     * @return array|string[]
     */
    public function getRequiredAttributes(): array
    {
        $attributes = $this->scopeConfig->getValue(self::XML_PATH_IXPFNG_PRODUCT_PURCHASE_REQUIRED_ATTRIBUTES) ?? "";
        return explode(",", $attributes) ?? [];
    }

    public function getShopObjectId(): string
    {
        return $this->scopeConfig->getValue(self::XML_PATH_GENERAL_SHOP_OBJECT_ID) ?? "";
    }

    public function isWebhookEnable(): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_IXPFNG_PRODUCT_PURCHASE_WEBHOOK_ENABLE) == 1;
    }

    public function getWebhookUrl(): string
    {
        return $this->scopeConfig->getValue(self::XML_PATH_IXPFNG_PRODUCT_PURCHASE_WEBHOOK_URL) ?? "";
    }

    public function getWebhookSecret(): string
    {
        return $this->scopeConfig->getValue(self::XML_PATH_IXPFNG_PRODUCT_PURCHASE_WEBHOOK_SECRET) ?? "";
    }

    public function isCronEnabled(): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_IXPFNG_PRODUCT_PURCHASE_CRON_ENABLE) == 1;
    }

    /**
     * @param $sku
     * @return bool|\Magento\Catalog\Model\AbstractModel
     */
    protected function getProduct($sku)
    {
        if (!is_null($sku) && $sku != '') {
            $product = $this->product->loadByAttribute('sku', $sku);
            if ($product && $product->getId()) {
                return $product;
            }
        }

        return false;
    }
}