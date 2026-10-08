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

namespace CarpetAvenue\FeedExport\Model\Export;

use CarpetAvenue\FeedExport\Api\Data\ProfileInterface;
use CarpetAvenue\Productify\ProductStatus\Api\ConfigInterface as ProductStatusConfig;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Helper\Stock as StockHelper;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds the filtered product collection for one profile.
 */
class CollectionBuilder
{
    /**
     * Attributes every run needs regardless of the template (filters, prices, URLs, images, mapping).
     */
    private const BASE_ATTRIBUTES = [
        'sku', 'name', 'status', 'visibility', 'url_key', 'type_id', 'image', 'small_image', 'thumbnail',
        'price', 'special_price', 'special_from_date', 'special_to_date', 'tax_class_id',
        'google_product_category', 'weight',
    ];

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resourceConnection,
        private readonly StockHelper $stockHelper,
        private readonly EavConfig $eavConfig,
        private readonly ProductStatusConfig $productStatusConfig,
        private readonly InventoryJoin $inventoryJoin
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function build(ProfileInterface $profile, FieldResolver $fieldResolver): Collection
    {
        $collection = $this->collectionFactory->create();
        $collection->addTaxPercents();

        $storeId = $profile->getStoreId();
        if ($storeId) {
            try {
                $websiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();
            } catch (NoSuchEntityException $e) {
                throw new LocalizedException(
                    __('Store ID %1 of this profile does not exist anymore. Select a valid store view.', $storeId)
                );
            }
            $collection->getSelect()->joinLeft(
                ['price_index' => $this->resourceConnection->getTableName('catalog_product_index_price')],
                $this->resourceConnection->getConnection()->quoteInto(
                    'price_index.entity_id = e.entity_id AND price_index.customer_group_id = ?',
                    $profile->getCustomerGroupId()
                ) . $this->resourceConnection->getConnection()->quoteInto(
                    ' AND price_index.website_id = ?',
                    $websiteId
                ),
                ['min_price', 'max_price', 'tier_price', 'final_price']
            );
            $collection->addStoreFilter($storeId);
            $collection->setStore($storeId);
        }

        $collection->addAttributeToSelect($this->getAttributesToSelect($fieldResolver));

        if ($profile->getProductVisibility()) {
            $collection->addAttributeToFilter('visibility', ['in' => $profile->getProductVisibility()]);
        }
        if ($profile->getProductStatus()) {
            $collection->addAttributeToFilter('status', ['in' => $profile->getProductStatus()]);
        }
        if ($profile->getProductStatus() === [Status::STATUS_ENABLED]) {
            $this->applyProductStatusRules($collection);
        }
        if ($profile->isInstockOnly()) {
            $this->stockHelper->addInStockFilterToCollection($collection);
        }
        if ($profile->getExcludedProductTypes()) {
            $collection->addAttributeToFilter('type_id', ['nin' => $profile->getExcludedProductTypes()]);
        }
        if ($profile->getSkuFilter()) {
            $collection->addAttributeToFilter('sku', ['in' => $profile->getSkuFilter()]);
        }

        $this->inventoryJoin->apply($collection, $storeId);
        $collection->setOrder('entity_id', 'ASC');

        return $collection;
    }

    /**
     * CarpetAvenue_Productify ProductStatus gates: approved / online / weight > 0 (same rules as storefront).
     */
    private function applyProductStatusRules(Collection $collection): void
    {
        if ($this->productStatusConfig->approveEnabled()) {
            $collection->addAttributeToFilter('approved', ['eq' => Boolean::VALUE_YES]);
        }
        if ($this->productStatusConfig->onlineEnabled()) {
            $collection->addAttributeToFilter('online', ['eq' => Boolean::VALUE_YES]);
        }
        if ($this->productStatusConfig->weightEnabled()) {
            $collection->addAttributeToFilter('weight', ['gt' => 0]);
        }
    }

    /**
     * @return string|string[]
     */
    private function getAttributesToSelect(FieldResolver $fieldResolver): string|array
    {
        if ($fieldResolver->isUnrestricted()) {
            return '*';
        }
        $attributes = self::BASE_ATTRIBUTES;
        foreach (array_keys($this->eavConfig->getEntityAttributes(Product::ENTITY)) as $code) {
            if ($fieldResolver->isRequired((string)$code)) {
                $attributes[] = (string)$code;
            }
        }
        return array_values(array_unique($attributes));
    }
}
