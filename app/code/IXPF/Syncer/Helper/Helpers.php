<?php

namespace IXPF\Syncer\Helper;

use Magento\Catalog\Helper\Image;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;

class Helpers extends AbstractHelper
{
    const XML_PATH_IXPFNG_PRODUCT_UPDATE_CONFIG_ENABLE = "ixpfng_product/product_update_config/enable";
    const XML_PATH_IXPFNG_PRODUCT_UPDATE_ALLOW_ATTRIBUTES = "ixpfng_product/product_update_config/allow_attributes";
    const XML_PATH_IXPFNG_PRODUCT_BULK_UPDATE_CONFIG_ENABLE = "ixpfng_product/product_bulk_update_config/enable";
    const XML_PATH_IXPFNG_PRODUCT_BULK_UPDATE_ALLOW_ATTRIBUTES = "ixpfng_product/product_bulk_update_config/allow_attributes";
    const XML_PATH_IXPFNG_PRODUCT_GOOGLE_BUCKET_ENABLE = "ixpfng_product/google_bucket_config/enable";
    const XML_PATH_IXPFNG_PRODUCT_GOOGLE_BUCKET_NAME = "ixpfng_product/google_bucket_config/bucket_name";
    const XML_PATH_IXPFNG_PRODUCT_GOOGLE_BUCKET_CONFIG_FILE = "ixpfng_product/google_bucket_config/json_file";
    const XML_PATH_IXPFNG_PRODUCT_PHOTOSTUDIO_PREVIOUS_FUNCTIONALITY_ENABLE = "ixpfng_product/google_bucket_config/enable_previous_functionality";
    const XML_PATH_IXPFNG_PRODUCT_PHOTOSTUDIO_CRON_ENABLE = "ixpfng_product/google_bucket_config/enable_cron";
    const XML_PATH_IXPFNG_PRODUCT_SAVE_METHOD = "ixpfng_product/google_bucket_config/product_save_method";
    const XML_PATH_IXPFNG_PRODUCT_CATEGORY_POSITION_UPDATE = "ixpfng_product/category_position/enable";
    const XML_PATH_IXPFNG_BULK_CATEGORY_ASSIGN = "ixpfng_product/product_bulk_update_config/category_assign_enable";
    const XML_PATH_WEBHOOK_DISPATCH_LIMIT = 'ixpfng_product/google_bucket_config/webhook_dispatch_limit';
    const XML_PATH_WEBHOOK_PARALLEL_PROCESSING = 'ixpfng_product/google_bucket_config/webhook_parallel_processing';
    const XML_PATH_WEBHOOK_PARALLEL_WORKERS = 'ixpfng_product/google_bucket_config/webhook_parallel_workers';

    private const DEFAULT_DISPATCH_LIMIT = 25;
    private const DEFAULT_WORKERS = 1;

    public function __construct(
        Context $context,
        protected Filesystem $filesystem,
        protected UrlInterface $_url,
        protected Image $imageHelper
    )
    {
        parent::__construct($context);
    }

    public function getEnableProductUpdateConfig(): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_IXPFNG_PRODUCT_UPDATE_CONFIG_ENABLE) == 1;
    }

    public function getEnableProductBulkUpdateConfig(): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_IXPFNG_PRODUCT_BULK_UPDATE_CONFIG_ENABLE) == 1;
    }

    public function getProductUpdateAllowAttributes(): array
    {
        $attributes = $this->scopeConfig->getValue(self::XML_PATH_IXPFNG_PRODUCT_UPDATE_ALLOW_ATTRIBUTES);
        return explode(",", $attributes) ?? [];
    }

    public function getProductUpdateBulkAllowAttributes(): array
    {
        $attributes = $this->scopeConfig->getValue(self::XML_PATH_IXPFNG_PRODUCT_BULK_UPDATE_ALLOW_ATTRIBUTES);
        if (is_null($attributes)) {
            return [];
        }
        return explode(",", $attributes) ?? [];
    }

    public function getEnableGoogleBucketConfig(): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_IXPFNG_PRODUCT_GOOGLE_BUCKET_ENABLE) == 1;
    }
    public function getEnablePhotoStudioPreviousFunctionality(): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_IXPFNG_PRODUCT_PHOTOSTUDIO_PREVIOUS_FUNCTIONALITY_ENABLE) == 1;
    }

    /**
     * Returns true when the PhotoStudio cron (bucket download + queue run) is enabled.
     */
    public function isPhotoStudioCronEnabled(): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_IXPFNG_PRODUCT_PHOTOSTUDIO_CRON_ENABLE) == 1;
    }

    /**
     * Returns the configured product save method value ('0' = ORM, '1' = Raw DB).
     */
    public function getProductSaveMethod(): string
    {
        return (string)($this->scopeConfig->getValue(self::XML_PATH_IXPFNG_PRODUCT_SAVE_METHOD) ?? '0');
    }

    /**
     * Returns true when the admin has selected the Raw DB save method.
     */
    public function isRawDbSaveMethod(): bool
    {
        return $this->getProductSaveMethod() === '1';
    }

    public function getGoogleBucketName(): string
    {
        return $this->scopeConfig->getValue(self::XML_PATH_IXPFNG_PRODUCT_GOOGLE_BUCKET_NAME) ?? '';
    }
    public function getGoogleBucketConfigJsonFile(): string
    {
        $fileName = $this->scopeConfig->getValue(self::XML_PATH_IXPFNG_PRODUCT_GOOGLE_BUCKET_CONFIG_FILE);
        $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $filePath = $mediaDirectory->getAbsolutePath('persistent/google/bucket/config').'/' . $fileName;
        if (file_exists($filePath)) {
            return $filePath;
        } else {
            return "";
        }
    }

    public function getGoogleBucketImageInitDownloadPath()
    {
        $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $importPath = 'import/google/bucket/';
        $mediaDirectory->create($importPath);
        return $mediaDirectory->getAbsolutePath($importPath);
    }

    public function getProductImageUploadPath(): string
    {
        $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $filePath = $mediaDirectory->getAbsolutePath('catalog/product');
        return $filePath;
    }

    public function getEnableCategoryPositionUpdate(): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_IXPFNG_PRODUCT_CATEGORY_POSITION_UPDATE) == 1;
    }

    public function getEnableBulkCategoryAssign(): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_IXPFNG_BULK_CATEGORY_ASSIGN) == 1;
    }

    public function getImageUrl($imagePath): string
    {
        if ($imagePath && $imagePath !== 'no_selection') {
            // Get base media URL and construct the full image URL
            $baseMediaUrl = $this->_url->getBaseUrl(['_type' => UrlInterface::URL_TYPE_MEDIA]);
            $mainImageUrl = $baseMediaUrl . 'catalog/product' . $imagePath;
        } else {
            // Use a placeholder image if no image is set
            $mainImageUrl = $this->imageHelper->getDefaultPlaceholderUrl('image');
        }
        return $mainImageUrl;
    }

    public function getWebhookDispatchLimit(): int
    {
        $value = (int)$this->scopeConfig->getValue(self::XML_PATH_WEBHOOK_DISPATCH_LIMIT);
        return $value > 0 ? $value : self::DEFAULT_DISPATCH_LIMIT;
    }

    public function isWebhookParallelProcessingEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_WEBHOOK_PARALLEL_PROCESSING);
    }

    public function getWebhookParallelWorkers(): int
    {
        $value = (int)$this->scopeConfig->getValue(self::XML_PATH_WEBHOOK_PARALLEL_WORKERS);
        return $value > 0 ? $value : self::DEFAULT_WORKERS;
    }
}