<?php

namespace CarpetAvenue\Productify\ProductStatus\Model\Sitemap\ResourceModel\Catalog;

use Magento\Catalog\Helper\Product as HelperProduct;
use Magento\Store\Model\ScopeInterface;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
class Product extends \Magento\Sitemap\Model\ResourceModel\Catalog\Product{
    protected \CarpetAvenue\Productify\ProductStatus\Api\ConfigInterface $statusConfig;
    private \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig;
    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context,
        \Magento\Sitemap\Helper\Data $sitemapData,
        \Magento\Catalog\Model\ResourceModel\Product $productResource,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Catalog\Model\Product\Visibility $productVisibility,
        \Magento\Catalog\Model\Product\Attribute\Source\Status $productStatus,
        \Magento\Catalog\Model\ResourceModel\Product\Gallery $mediaGalleryResourceModel,
        \Magento\Catalog\Model\Product\Gallery\ReadHandler $mediaGalleryReadHandler,
        \Magento\Catalog\Model\Product\Media\Config $mediaConfig,
        \CarpetAvenue\Productify\ProductStatus\Api\ConfigInterface $statusConfig,
        $connectionName = null,
        ?\Magento\Catalog\Model\Product $productModel = null,
        ?\Magento\Catalog\Helper\Image $catalogImageHelper = null,
        ?\Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig = null,
        ?\Magento\Catalog\Model\Product\Image\UrlBuilder $urlBuilder = null
    ) {
        /*$this->_productResource = $productResource;
        $this->_storeManager = $storeManager;
        $this->_productVisibility = $productVisibility;
        $this->_productStatus = $productStatus;
        $this->mediaGalleryResourceModel = $mediaGalleryResourceModel;
        $this->mediaGalleryReadHandler = $mediaGalleryReadHandler;
        $this->_mediaConfig = $mediaConfig;
        $this->_sitemapData = $sitemapData;
        $this->productModel = $productModel ?: ObjectManager::getInstance()->get(\Magento\Catalog\Model\Product::class);
        $this->catalogImageHelper = $catalogImageHelper;
        $this->imageUrlBuilder = $urlBuilder ?? ObjectManager::getInstance()->get(UrlBuilder::class);
        $this->catalogImageHelper = $catalogImageHelper ?: ObjectManager::getInstance()
            ->get(\Magento\Catalog\Helper\Image::class);*/
        $this->scopeConfig = $scopeConfig ?: \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $this->statusConfig = $statusConfig;

        parent::__construct($context,
            $sitemapData,
            $productResource,
            $storeManager,
            $productVisibility,
            $productStatus,
            $mediaGalleryResourceModel,
            $mediaGalleryReadHandler,
            $mediaConfig,
            $connectionName,
            $productModel,
            $catalogImageHelper,
            $scopeConfig,
            $urlBuilder);
    }

    /**
     * Add attribute to filter
     *
     * @param int $storeId
     * @param string $attributeCode
     * @param mixed $value
     * @param string $type
     *
     * @return \Magento\Framework\DB\Select|bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _addFilter($storeId, $attributeCode, $value, $type = '=')
    {
        switch ($type) {
            case '=':
                parent::_addFilter($storeId, $attributeCode, $value, $type);
                return $this->_select;
            case 'in':
                parent::_addFilter($storeId, $attributeCode, $value, $type);
                return $this->_select;
            case 'gt':
                $conditionRule = '>?';
                break;
            default:
                return false;
        }

        if (!$this->_select instanceof \Magento\Framework\DB\Select) {
            return false;
        }

        $attribute = $this->_getAttribute($attributeCode);
        if ($attribute['backend_type'] == 'static') {
            $this->_select->where('e.' . $attributeCode . $conditionRule, $value);
        } else {
            $this->_joinAttribute($storeId, $attributeCode);
            if ($attribute['is_global']) {
                $this->_select->where('t1_' . $attributeCode . '.value' . $conditionRule, $value);
            } else {
                $ifCase = $this->getConnection()->getCheckSql(
                    't2_' . $attributeCode . '.value_id > 0',
                    't2_' . $attributeCode . '.value',
                    't1_' . $attributeCode . '.value'
                );
                $this->_select->where('(' . $ifCase . ')' . $conditionRule, $value);
            }
        }

        return $this->_select;
    }

    public function getCollection($storeId)
    {
        if (!$this->statusConfig->approveEnabled() && !$this->statusConfig->onlineEnabled() && !$this->statusConfig->weightEnabled()){
            parent::getCollection($storeId);
        }
        $products = [];

        $store = $this->_storeManager->getStore($storeId);
        if (!$store) {
            return false;
        }

        $connection = $this->getConnection();
        $urlRewriteMetaDataCondition = '';
        if (!$this->isCategoryProductURLsConfig($storeId)) {
            $urlRewriteMetaDataCondition = ' AND url_rewrite.metadata IS NULL';
        }

        $this->_select = $connection->select()->from(
            ['e' => $this->getMainTable()],
            [$this->getIdFieldName(), $this->_productResource->getLinkField(), 'updated_at']
        )->joinInner(
            ['w' => $this->getTable('catalog_product_website')],
            'e.entity_id = w.product_id',
            []
        )->joinLeft(
            ['url_rewrite' => $this->getTable('url_rewrite')],
            'e.entity_id = url_rewrite.entity_id AND url_rewrite.is_autogenerated = 1'
            . $urlRewriteMetaDataCondition
            . $connection->quoteInto(' AND url_rewrite.store_id = ?', $store->getId())
            . $connection->quoteInto(' AND url_rewrite.entity_type = ?', ProductUrlRewriteGenerator::ENTITY_TYPE),
            ['url' => 'request_path']
        )->where(
            'w.website_id = ?',
            $store->getWebsiteId()
        );

        $this->_addFilter($store->getId(), 'visibility', $this->_productVisibility->getVisibleInSiteIds(), 'in');
        $this->_addFilter($store->getId(), 'status', $this->_productStatus->getVisibleStatusIds(), 'in');

        if($this->statusConfig->approveEnabled()){
            $this->_addFilter(0, 'approved', \Magento\Eav\Model\Entity\Attribute\Source\Boolean::VALUE_YES, '=');
        }
        if($this->statusConfig->onlineEnabled()){
            $this->_addFilter(0, 'online', \Magento\Eav\Model\Entity\Attribute\Source\Boolean::VALUE_YES, '=');
        }
        if ($this->statusConfig->weightEnabled()){
            $this->_addFilter(0, 'weight', 0, 'gt');
        }

        // Join product images required attributes
        $imageIncludePolicy = $this->_sitemapData->getProductImageIncludePolicy($store->getId());
        if (\Magento\Sitemap\Model\Source\Product\Image\IncludeImage::INCLUDE_NONE != $imageIncludePolicy) {
            $this->_joinAttribute($store->getId(), 'name', 'name');
            if (\Magento\Sitemap\Model\Source\Product\Image\IncludeImage::INCLUDE_ALL == $imageIncludePolicy) {
                $this->_joinAttribute($store->getId(), 'thumbnail', 'thumbnail');
            } elseif (\Magento\Sitemap\Model\Source\Product\Image\IncludeImage::INCLUDE_BASE == $imageIncludePolicy) {
                $this->_joinAttribute($store->getId(), 'image', 'image');
            }
        }

        $query = $connection->query($this->prepareSelectStatement($this->_select));
        while ($row = $query->fetch()) {
            $product = $this->_prepareProduct($row, $store->getId());
            $products[$product->getId()] = $product;
        }

        return $products;
    }
    private function isCategoryProductURLsConfig($storeId)
    {
        return $this->scopeConfig->isSetFlag(
            HelperProduct::XML_PATH_PRODUCT_URL_USE_CATEGORY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
