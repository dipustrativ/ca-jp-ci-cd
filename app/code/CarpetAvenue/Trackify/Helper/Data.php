<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Marketing
 * @package   CarpetAvenue_Trackify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Trackify\Helper;

use CarpetAvenue\Core\Helper\SysConfig;
use Exception;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Helper\Image as ImageHelper;

class Data  extends AbstractHelper
{
    const XML_PATH_ACTIVE = 'carpetavenue_analytics/tagmanager/active';
    const XML_PATH_PRODUCT_SCHEMA_JSON_LD = 'productify/product_schema_json_ld/enabled';

    public function __construct(
        Context $context,
        protected StoreManagerInterface $storeManager,
        protected ResourceConnection $resourceConnection,
        protected ImageHelper $_imageHelper,
        protected SysConfig $sysConfig
    ) {
        parent::__construct($context);
    }

    /**
     * @return boolean
     */
    public function isProductSchemaManagerEnabled(): bool
    {
        return (bool) $this->sysConfig->getConfig(self::XML_PATH_PRODUCT_SCHEMA_JSON_LD);
    }

    /**
     * Check if GTM is enabled.
     * @return boolean
     */
    public function isGoogleTagManagerEnabled(): bool
    {
        return (bool) $this->sysConfig->getConfig(self::XML_PATH_ACTIVE);
    }

    /**
     * Add slashes to string and prepares string for javascript.
     *
     * @param string $str
     * @return string
     */
    public function escapeSingleQuotes(string $str): string
    {
        return str_replace("'", "\'", $str);
    }

    /**
     * Get current currency code
     * @return string
     */
    public function getCurrentCurrencyCode(): string
    {
        try {
            return $this->storeManager->getStore()->getCurrentCurrencyCode();
        } catch (Exception) {
            return '';
        }
    }

    /**
     *
     * Get category names for given IDs
     * @param array $categoryIds
     * @return array
     */
    public function getCategoryForNames(array $categoryIds): array
    {
        // Get category names efficiently using resource model
        $categoryNames = [];
        $categoryNamesItems = [];
        try {
            if (!empty($categoryIds)) {
                $connection = $this->resourceConnection->getConnection();

                // Direct SQL query to get category names for current store
                $storeId = $this->storeManager->getStore()->getId();

                $select = $connection->select()
                    ->from(['ce' => $this->resourceConnection->getTableName('catalog_category_entity')], ['entity_id'])
                    ->joinLeft(
                        ['cev' => $this->resourceConnection->getTableName('catalog_category_entity_varchar')],
                        'ce.entity_id = cev.entity_id AND cev.attribute_id = (SELECT attribute_id FROM ' . $this->resourceConnection->getTableName('eav_attribute') . ' WHERE attribute_code = "name" AND entity_type_id = 3)',
                        ['value']
                    )
                    ->where('ce.entity_id IN (?)', $categoryIds)
                    ->where('cev.store_id IN (0, ?)', $storeId)
                    ->order('cev.store_id DESC');

                $result = $connection->fetchPairs($select);

                // Maintain original category order
                foreach ($categoryIds as $categoryId) {
                    if (isset($result[$categoryId])) {
                        $categoryNames[] = $result[$categoryId];
                    }
                }
            }

            // Format for GA4 tracking
            if ($categoryNames) {
                $categoryNamesItems['item_category'] = $categoryNames[0];
                for ($i = 1; $i < count($categoryNames); $i++) {
                    $key = $i + 1;
                    $categoryNamesItems["item_category$key"] = $categoryNames[$i];
                }
            }
            return $categoryNamesItems;
        } catch (Exception) {
            return [];
        }
    }

    /**
     *
     * Get category names for given IDs
     * @param array $categoryIds
     * @return array
     */
    public function getCategoryForNamesAndIds(array $categoryIds): array
    {
        // Get category names efficiently using resource model
        $categoryNames = [];
        try {
            if (!empty($categoryIds)) {
                $connection = $this->resourceConnection->getConnection();

                // Direct SQL query to get category names for current store
                $storeId = $this->storeManager->getStore()->getId();

                $select = $connection->select()
                    ->from(['ce' => $this->resourceConnection->getTableName('catalog_category_entity')], ['entity_id'])
                    ->joinLeft(
                        ['cev' => $this->resourceConnection->getTableName('catalog_category_entity_varchar')],
                        'ce.entity_id = cev.entity_id AND cev.attribute_id = (SELECT attribute_id FROM ' . $this->resourceConnection->getTableName('eav_attribute') . ' WHERE attribute_code = "name" AND entity_type_id = 3)',
                        ['value', 'cev.store_id']
                    )
                    ->where('ce.entity_id IN (?)', $categoryIds)
                    ->where('cev.store_id IN (0, ?)', $storeId)
                    ->order('cev.store_id DESC');

                $result = $connection->fetchAll($select);

                // Maintain original category order
                foreach ($result as $row) {
                    $entityId = $row['entity_id'];
                    if (isset($categoryNames[$entityId])) {
                        continue;
                    } else {
                        $categoryNames[$entityId] = $row['value'];
                    }
                }
            }
            return $categoryNames;
        } catch (Exception) {
            return [];
        }
    }

    public function getCurrencyCode(): ?string
    {
        try {
            return $this->storeManager->getStore()->getCurrentCurrencyCode();
        } catch (Exception) {
            return '';
        }
    }

    /**
     * Get Product Image
     *
     * @param Product|null $product
     * @param string $imageId
     * @param int $width
     * @param int $height
     * @param array $attribute
     * @return false|string
     */
    public function getProductImageUrl(
        ?Product $product = null,
        string $imageId = "thumbnail",
        int $width = 200,
        int $height = 200,
        array $attribute = ['type' => 'thumbnail']
    ) {
        if (!$product) {
            return false;
        }
        //-- ['small_image', 'thumbnail', 'image']
        return $this->_imageHelper->init($product, $imageId, $attribute)
            ->constrainOnly(true)
            ->keepAspectRatio(true)
            ->keepTransparency(true)
            ->resize($width, $height)
            ->getUrl();
    }
}