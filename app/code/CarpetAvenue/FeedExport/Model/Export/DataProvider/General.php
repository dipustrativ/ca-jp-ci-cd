<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_FeedExport
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\FeedExport\Model\Export\DataProvider;

use CarpetAvenue\FeedExport\Model\Export\ExportContext;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Gallery\ReadHandler as GalleryReadHandler;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Model\Config as TaxConfig;

/**
 * Attribute values, prices, image/product URLs, mapped Google category, gallery images.
 * Field names match the old Xtento feeds so existing XSL templates keep working.
 */
class General implements DataProviderInterface
{
    /** Keys filled by the collection joins; not product attributes. */
    private const INTERNAL_PREFIX = 'fe_';

    private const IMAGE_ATTRIBUTES = ['image', 'small_image', 'thumbnail'];

    private const SKIPPED_INPUTS = ['weee', 'media_gallery', 'gallery'];

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductResource $productResource,
        private readonly TaxConfig $taxConfig,
        private readonly CatalogHelper $catalogHelper,
        private readonly ImageHelper $imageHelper,
        private readonly GalleryReadHandler $galleryReadHandler,
        private readonly AttributeSetRepositoryInterface $attributeSetRepository,
        private readonly TimezoneInterface $timezone
    ) {
    }

    public function provide(Product $product, ExportContext $context): array
    {
        return $this->buildProductData($product, $context);
    }

    /**
     * Also used by the parent provider to render parent_item.
     *
     * @return array<string, mixed>
     */
    public function buildProductData(Product $product, ExportContext $context): array
    {
        $data = [];
        $storeId = $context->getStoreId();
        if ($storeId) {
            $product->setStoreId($storeId);
        }
        $context->write($data, 'store_id', $storeId);
        $context->writeIfRequired(
            $data,
            'original_store_price',
            static fn() => $product->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue()
        );

        foreach ($product->getData() as $key => $value) {
            $this->writeAttribute($product, (string)$key, $value, $data, $context);
        }

        $this->writeComputedFields($product, $data, $context);

        if ($context->isRequired('images')) {
            $data['images'] = $this->getGalleryImages($product, $context);
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeAttribute(Product $product, string $key, mixed $value, array &$data, ExportContext $context): void
    {
        if ($key === 'entity_id' || str_starts_with($key, self::INTERNAL_PREFIX)) {
            return;
        }
        if ($key === 'price') {
            $context->write($data, 'original_price', $value);
            return;
        }
        if (!$context->isRequired($key)) {
            if ($context->isRequired($key . '_raw')) {
                $context->write($data, $key . '_raw', $value);
            }
            return;
        }
        if ($key === 'cost') {
            $context->write(
                $data,
                'cost',
                $this->productResource->getAttributeRawValue((int)$product->getId(), 'cost', $context->getStoreId())
            );
            return;
        }
        if (in_array($key, ['min_price', 'max_price', 'special_price'], true) && $value !== null && $value !== '') {
            $value = $this->addTax($product, (float)$value, $key, $data, $context);
        }
        if ($key === 'qty' && is_numeric($value)) {
            $value = sprintf('%d', $value);
        }
        if (in_array($key, self::IMAGE_ATTRIBUTES, true)) {
            $this->writeImage($product, $key, (string)$value, $data, $context);
            return;
        }

        $attribute = $product->getResource()->getAttribute($key);
        $text = '';
        if ($attribute) {
            if ($attribute instanceof Attribute) {
                $attribute->setStoreId($product->getStoreId());
            }
            $input = $attribute->getFrontendInput();
            if ($input === 'media_image') {
                $context->write($data, $key . '_raw', $value);
                $context->write($data, $key, $this->getMediaUrl((string)$value, $context));
                return;
            }
            if (in_array($input, self::SKIPPED_INPUTS, true)) {
                return;
            }
            if ($attribute->usesSource()) {
                try {
                    $text = $product->getAttributeText($key);
                } catch (\Exception $e) {
                    return;
                }
            }
        }

        if (is_array($text) && $text) {
            foreach (array_values($text) as $index => $label) {
                if (is_scalar($label)) {
                    $context->write($data, $key . '_value_' . $index, $label);
                }
            }
            $context->write($data, $key, implode(',', array_filter($text, 'is_scalar')));
        } elseif ($text !== '' && $text !== false && $text !== null) {
            if ($attribute && $attribute->getFrontendInput() === 'multiselect') {
                $context->write($data, $key . '_value_0', $text);
            }
            $context->write($data, $key, $text);
        } else {
            $context->write($data, $key, $value);
        }

        if (in_array($key, ['visibility', 'status', 'tax_class_id'], true) || $context->isRequired($key . '_raw')) {
            $context->write($data, $key . '_raw', $value);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeComputedFields(Product $product, array &$data, ExportContext $context): void
    {
        $context->writeIfRequired($data, 'xtento_mapped_category', fn() => $this->getMappedCategory($product, $context));
        $context->writeIfRequired($data, 'mapped_category', fn() => $this->getMappedCategory($product, $context));
        $context->writeIfRequired($data, 'product_url', function () use ($product, $context) {
            $url = (string)$product->getProductUrl(false);
            return $context->getProfile()->isUrlRemoveStore() ? strtok($url, '?') : $url;
        });

        $price = $context->isRequired('price') ? $this->getPrice($product, $data, $context) : null;
        if ($price !== null) {
            $context->write($data, 'price', $price);
            $context->writeIfRequired(
                $data,
                'price_incl_tax',
                fn() => $this->catalogHelper->getTaxPrice(
                    $product,
                    $price,
                    true,
                    null,
                    null,
                    null,
                    $this->storeManager->getStore($product->getStoreId())
                )
            );
        }
        $context->writeIfRequired(
            $data,
            'final_price',
            static fn() => $product->getPriceInfo()->getPrice('final_price')->getValue()
        );
        $context->writeIfRequired($data, 'attribute_set_name', fn() => $this->getAttributeSetName($product, $context));
        $context->writeIfRequired($data, 'special_price_active', fn() => (int)$this->isSpecialPriceActive($product));
        $context->writeIfRequired($data, 'website_codes', function () use ($product) {
            $codes = [];
            foreach ($product->getWebsiteIds() as $websiteId) {
                $codes[] = $this->storeManager->getWebsite($websiteId)->getCode();
            }
            return implode(',', array_unique($codes));
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeImage(Product $product, string $key, string $value, array &$data, ExportContext $context): void
    {
        $context->write($data, $key . '_raw', $value);
        $context->write($data, $key, $this->getMediaUrl($value, $context));
        if ($value !== '' && $value !== 'no_selection' && $context->isRequired($key . '_cache_url')) {
            $url = $this->imageHelper->init($product, $key)->setImageFile($value)->getUrl();
            $context->write($data, $key . '_cache_url', $this->stripPub($url, $context));
        }
    }

    private function getMediaUrl(string $file, ExportContext $context): string
    {
        $url = $this->storeManager->getStore($context->getStoreId())->getBaseUrl(UrlInterface::URL_TYPE_MEDIA)
            . 'catalog/product/' . ltrim($file, '/');
        return $this->stripPub($url, $context);
    }

    private function stripPub(string $url, ExportContext $context): string
    {
        return $context->getProfile()->isRemovePubFromUrls() ? str_replace('/pub/', '/', $url) : $url;
    }

    /**
     * Deepest mapped taxonomy path of the product categories, else google_product_category attribute.
     */
    private function getMappedCategory(Product $product, ExportContext $context): string
    {
        $mapping = $context->getCache('category_mapping', static fn() => $context->getProfile()->getCategoryMapping());
        $mapped = '';
        $deepest = 0;
        foreach ($product->getCategoryIds() as $categoryId) {
            $path = (string)($mapping[$categoryId] ?? '');
            if ($path === '') {
                continue;
            }
            if (!str_contains($path, '>')) {
                $mapped = $path;
                break;
            }
            $level = substr_count($path, '>');
            if ($level > $deepest) {
                $deepest = $level;
                $mapped = $path;
            }
        }
        return $mapped !== '' ? $mapped : (string)$product->getData('google_product_category');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function getPrice(Product $product, array &$data, ExportContext $context): ?float
    {
        $price = (float)$product->getFinalPrice();
        if ($price == 0.0) {
            // No price at all (e.g. no price index row): leave the field empty instead of "0".
            $minPrice = $product->getData('min_price');
            if ($minPrice === null || $minPrice === '') {
                return null;
            }
            $price = (float)$minPrice;
        }
        return $this->addTax($product, $price, 'price', $data, $context);
    }

    /**
     * Returns gross price and writes <key>_excl_tax, using the tax_percent added by addTaxPercents().
     *
     * @param array<string, mixed> $data
     */
    private function addTax(Product $product, float $price, string $key, array &$data, ExportContext $context): float
    {
        $taxPercent = (float)$product->getData('tax_percent');
        if ($taxPercent <= 0) {
            $context->write($data, $key . '_excl_tax', $price);
            return $price;
        }
        if ($this->taxConfig->priceIncludesTax($context->getStoreId())) {
            $context->write($data, $key . '_excl_tax', $price / (1 + $taxPercent / 100));
            return $price;
        }
        $context->write($data, $key . '_excl_tax', $price);
        return $price * (1 + $taxPercent / 100);
    }

    private function getAttributeSetName(Product $product, ExportContext $context): string
    {
        $setId = (int)$product->getAttributeSetId();
        return (string)$context->getCache('attribute_set_' . $setId, function () use ($setId) {
            try {
                return $this->attributeSetRepository->get($setId)->getAttributeSetName();
            } catch (NoSuchEntityException $e) {
                return '';
            }
        });
    }

    private function isSpecialPriceActive(Product $product): bool
    {
        if (!$product->getSpecialFromDate()) {
            return false;
        }
        $today = $this->timezone->date()->setTime(0, 0);
        $from = $this->timezone->date(new \DateTime((string)$product->getSpecialFromDate()))->setTime(0, 0);
        if ($today < $from) {
            return false;
        }
        if ($product->getSpecialToDate()) {
            $to = $this->timezone->date(new \DateTime((string)$product->getSpecialToDate()))->setTime(0, 0);
            if ($today > $to) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getGalleryImages(Product $product, ExportContext $context): array
    {
        $this->galleryReadHandler->execute($product);
        $images = [];
        foreach ($product->getMediaGalleryImages() ?: [] as $image) {
            $node = [];
            foreach ($image->getData() as $key => $value) {
                if ($key === 'url') {
                    $value = $this->stripPub((string)$value, $context);
                }
                if (is_scalar($value) && $value !== '') {
                    $node[(string)$key] = $value;
                }
            }
            $images[] = $node;
        }
        return $images;
    }
}
